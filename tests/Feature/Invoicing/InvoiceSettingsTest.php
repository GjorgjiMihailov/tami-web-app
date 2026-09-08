<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\InvoiceSettings;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function client(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('client');

        return $user;
    }

    public function test_a_client_can_open_the_settings_for_their_own_company(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->client($company))
            ->get(route('invoice-settings.index', $company))
            ->assertOk()
            ->assertSee('Формат на бројот на фактурата');
    }

    public function test_a_client_cannot_open_the_settings_of_another_company(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();

        $this->actingAs($this->client($own))
            ->get(route('invoice-settings.index', $other))
            ->assertForbidden();
    }

    public function test_a_client_can_save_the_format(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->client($company))
            ->test(InvoiceSettings::class, ['company' => $company])
            ->set('includeYear', true)
            ->set('yearFirst', false)
            ->set('yearDigits', 2)
            ->set('separator', '-')
            ->set('padding', 5)
            ->set('prefix', '')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertFalse($company->invoice_number_year_first);
        $this->assertSame(2, $company->invoice_number_year_digits);
        $this->assertSame('-', $company->invoice_number_separator);
        $this->assertSame(5, $company->invoice_number_padding);
        $this->assertNull($company->invoice_number_prefix);
    }

    public function test_the_preview_follows_what_is_typed_before_saving(): void
    {
        $company = Company::factory()->create();
        $year = (int) now()->year;

        Livewire::actingAs($this->client($company))
            ->test(InvoiceSettings::class, ['company' => $company])
            ->assertViewHas('preview', $year.'/1')
            ->set('separator', 'none')
            ->set('padding', 4)
            ->assertViewHas('preview', $year.'0001');
    }

    public function test_a_format_without_a_year_previews_only_the_number(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->client($company))
            ->test(InvoiceSettings::class, ['company' => $company])
            ->set('includeYear', false)
            ->set('padding', 5)
            ->assertViewHas('preview', '00001');
    }

    public function test_it_rejects_impossible_values(): void
    {
        $company = Company::factory()->create();

        Livewire::actingAs($this->client($company))
            ->test(InvoiceSettings::class, ['company' => $company])
            ->set('yearDigits', 3)
            ->set('padding', 7)
            ->set('prefix', str_repeat('А', 11))
            ->call('save')
            ->assertHasErrors(['yearDigits', 'padding', 'prefix']);
    }

    public function test_saving_does_not_touch_already_confirmed_invoices(): void
    {
        $company = Company::factory()->create();
        $invoice = \App\Models\SalesInvoice::factory()->for($company)->create([
            'status' => 'confirmed',
            'fiscal_year' => 2026,
            'invoice_number' => 3,
            'invoice_number_formatted' => '2026/3',
        ]);

        Livewire::actingAs($this->client($company))
            ->test(InvoiceSettings::class, ['company' => $company])
            ->set('separator', '-')
            ->set('padding', 5)
            ->call('save');

        $this->assertSame('2026/3', $invoice->fresh()->formattedNumber());
    }
}
