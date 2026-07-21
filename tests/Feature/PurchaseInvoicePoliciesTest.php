<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoicePoliciesTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_client_can_manage_their_own_companys_purchase_invoices(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $invoice = PurchaseInvoice::factory()->for($company)->create();

        $this->assertTrue($client->can('create', PurchaseInvoice::class));
        $this->assertTrue($client->can('update', $invoice));
        $this->assertTrue($client->can('view', $invoice));
    }

    public function test_client_cannot_manage_another_companys_purchase_invoices(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $invoice = PurchaseInvoice::factory()->for($otherCompany)->create();

        $this->assertFalse($client->can('view', $invoice));
        $this->assertFalse($client->can('update', $invoice));
    }

    public function test_accountant_not_assigned_to_a_company_cannot_view_its_purchase_invoices(): void
    {
        $companyTheyManage = Company::factory()->create();
        $companyTheyDoNotManage = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($companyTheyManage);
        $invoice = PurchaseInvoice::factory()->for($companyTheyDoNotManage)->create();

        $this->assertFalse($accountant->can('view', $invoice));
    }

    public function test_accountant_assigned_to_a_company_can_manage_its_purchase_invoices(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach($company);

        $invoice = PurchaseInvoice::factory()->for($company)->create();

        $this->assertTrue($accountant->can('view', $invoice));
        $this->assertTrue($accountant->can('update', $invoice));
        $this->assertTrue($accountant->can('create', PurchaseInvoice::class));
    }

    public function test_admin_can_manage_purchase_invoices_for_any_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $invoice = PurchaseInvoice::factory()->for($company)->create();

        $this->assertTrue($admin->can('view', $invoice));
        $this->assertTrue($admin->can('update', $invoice));
    }
}
