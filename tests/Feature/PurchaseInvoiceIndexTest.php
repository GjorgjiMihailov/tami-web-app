<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceIndex;
use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_it_lists_the_companys_purchase_invoices(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Acme Supplies DOOEL']);
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => 'SUP-100']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->assertSee('Acme Supplies DOOEL')
            ->assertSee('SUP-100');
    }

    public function test_it_filters_by_status(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'draft', 'supplier_invoice_number' => 'DRAFT-1']);
        PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed', 'supplier_invoice_number' => 'CONF-1']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceIndex::class, ['company' => $company])
            ->set('statusFilter', 'confirmed')
            ->assertSee('CONF-1')
            ->assertDontSee('DRAFT-1');
    }
}
