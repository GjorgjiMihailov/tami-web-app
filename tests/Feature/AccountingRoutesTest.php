<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountingRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_all_accounting_routes_render_successfully_for_an_admin(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('accounting.accounts.index', $company))->assertOk();
        $this->get(route('accounting.journal-entries.index', $company))->assertOk();
        $this->get(route('accounting.journal-entries.create', $company))->assertOk();
        $this->get(route('accounting.reports.ledger-card', $company))->assertOk();
        $this->get(route('accounting.reports.trial-balance', $company))->assertOk();
    }

    public function test_accounting_routes_require_authentication(): void
    {
        $company = Company::factory()->create();

        $this->get(route('accounting.accounts.index', $company))->assertRedirect(route('login'));
    }
}
