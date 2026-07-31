<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Livewire\EfakturaAccessRequests;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaCredentialModelRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_full_request_then_approve_flow_grants_access(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertFalse($company->hasEfakturaAccess());

        Livewire::actingAs($client)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('requestFirmEfakturaAccess');

        $this->assertFalse($company->fresh()->hasEfakturaAccess());

        Livewire::actingAs($admin)
            ->test(EfakturaAccessRequests::class)
            ->call('approve', $company->fresh()->id);

        $this->assertTrue($company->fresh()->hasEfakturaAccess());
    }

    public function test_navigation_link_only_renders_for_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create();
        $client->assignRole('client');

        $this->actingAs($admin)->get(route('dashboard'))->assertSee('е-Фактура барања');
        $this->actingAs($client)->get(route('dashboard'))->assertDontSee('е-Фактура барања');
    }
}
