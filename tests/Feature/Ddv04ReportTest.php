<?php

namespace Tests\Feature;

use App\Livewire\Reports\Ddv04Report;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Ddv04ReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_shows_computed_fields_for_the_selected_range(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed', 'invoice_date' => '2026-01-10']);
        $invoice->lines()->create(['description' => 'Sale', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_treatment' => 'standard']);
        $this->actingAs($admin);

        Livewire::test(Ddv04Report::class, ['company' => $company])
            ->set('from', '2026-01-01')
            ->set('to', '2026-01-31')
            ->assertSee('1.000,00')
            ->assertSee('180,00');
    }

    public function test_a_client_can_view_their_own_companys_report(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(Ddv04Report::class, ['company' => $company])
            ->assertSuccessful();
    }

    public function test_a_client_cannot_view_another_companys_report(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(Ddv04Report::class, ['company' => $otherCompany])
            ->assertForbidden();
    }

    public function test_a_past_working_year_opens_on_december_of_that_year(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
        WorkingYear::set($company, 2024);

        Livewire::test(Ddv04Report::class, ['company' => $company])
            ->assertSet('from', '2024-12-01')
            ->assertSet('to', '2024-12-31');
    }

    public function test_the_current_working_year_still_opens_on_this_month(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(Ddv04Report::class, ['company' => $company])
            ->assertSet('from', now()->startOfMonth()->toDateString())
            ->assertSet('to', now()->toDateString());
    }
}
