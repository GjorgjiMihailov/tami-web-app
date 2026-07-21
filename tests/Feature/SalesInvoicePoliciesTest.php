<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoicePoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_client_can_manage_their_own_companys_invoices(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $invoice = SalesInvoice::factory()->for($company)->create();

        $this->assertTrue($client->can('create', SalesInvoice::class));
        $this->assertTrue($client->can('update', $invoice));
        $this->assertTrue($client->can('view', $invoice));
    }

    public function test_client_cannot_manage_another_companys_invoices(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $invoice = SalesInvoice::factory()->for($otherCompany)->create();

        $this->assertFalse($client->can('view', $invoice));
        $this->assertFalse($client->can('update', $invoice));
    }

    public function test_accountant_not_assigned_to_a_company_cannot_view_its_invoices(): void
    {
        $companyTheyManage = Company::factory()->create();
        $companyTheyDoNotManage = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($companyTheyManage);
        $invoice = SalesInvoice::factory()->for($companyTheyDoNotManage)->create();

        $this->assertFalse($accountant->can('view', $invoice));
    }

    public function test_admin_can_manage_invoices_for_any_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $invoice = SalesInvoice::factory()->for($company)->create();

        $this->assertTrue($admin->can('update', $invoice));
    }
}
