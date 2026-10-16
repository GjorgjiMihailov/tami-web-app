<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\InvoiceSettings;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceNextNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
    }

    private function draft(Company $company): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();

        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'status' => 'draft',
            'invoice_date' => '2026-03-01',
            'due_date' => '2026-03-15',
            'warehouse_id' => null,
        ]);

        $invoice->lines()->create([
            'item_id' => null,
            'description' => 'Услуга',
            'quantity' => '1',
            'unit_price' => '1000.00',
            'vat_rate' => '18.00',
            'vat_treatment' => 'standard',
        ]);

        return $invoice->fresh();
    }

    public function test_confirm_starts_the_series_at_the_configured_next_number(): void
    {
        $company = Company::factory()->create(['invoice_number_next' => 201]);
        $user = User::factory()->create();

        $confirmed = app(SalesInvoiceService::class)->confirm($this->draft($company), $user->id);

        $this->assertSame(201, (int) $confirmed->invoice_number);
        $this->assertSame('2026/201', $confirmed->formattedNumber());
    }

    public function test_next_number_is_only_a_floor(): void
    {
        $company = Company::factory()->create(['invoice_number_next' => 201]);
        $user = User::factory()->create();
        $service = app(SalesInvoiceService::class);

        $service->confirm($this->draft($company), $user->id); // 201
        $second = $service->confirm($this->draft($company), $user->id);

        $this->assertSame(202, (int) $second->invoice_number);

        // Постоечка фактура над подлогата не се враќа наназад.
        $company->update(['invoice_number_next' => 50]);
        $third = $service->confirm($this->draft($company), $user->id);

        $this->assertSame(203, (int) $third->invoice_number);
    }

    public function test_without_a_next_number_the_series_is_unchanged(): void
    {
        $company = Company::factory()->create(['invoice_number_next' => null]);
        $user = User::factory()->create();

        $confirmed = app(SalesInvoiceService::class)->confirm($this->draft($company), $user->id);

        $this->assertSame(1, (int) $confirmed->invoice_number);
    }

    public function test_settings_screen_saves_the_next_number(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        Livewire::actingAs($user)
            ->test(InvoiceSettings::class, ['company' => $company])
            ->set('nextNumber', '201')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(201, $company->fresh()->invoice_number_next);
    }

    public function test_settings_screen_clears_the_next_number_when_left_empty(): void
    {
        $company = Company::factory()->create(['invoice_number_next' => 201]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        Livewire::actingAs($user)
            ->test(InvoiceSettings::class, ['company' => $company])
            ->assertSet('nextNumber', '201')
            ->set('nextNumber', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($company->fresh()->invoice_number_next);
    }

    public function test_settings_screen_rejects_zero_and_text_as_next_number(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        Livewire::actingAs($user)
            ->test(InvoiceSettings::class, ['company' => $company])
            ->set('nextNumber', '0')
            ->call('save')
            ->assertHasErrors(['nextNumber']);

        Livewire::actingAs($user)
            ->test(InvoiceSettings::class, ['company' => $company])
            ->set('nextNumber', 'abc')
            ->call('save')
            ->assertHasErrors(['nextNumber']);
    }
}
