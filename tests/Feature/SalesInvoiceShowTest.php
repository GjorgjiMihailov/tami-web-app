<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceShow;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceShowTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function seedAccounts(Company $company): void
    {
        foreach (['120', '740', '230', '660', '701', '100', '102'] as $code) {
            \App\Models\Account::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $code]
            );
        }
    }

    public function test_confirming_a_draft_invoice_from_the_show_screen(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->call('confirm')
            ->assertHasNoErrors();

        $this->assertSame('confirmed', $invoice->fresh()->status);
    }

    public function test_confirming_with_insufficient_stock_shows_an_error(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'warehouse_id' => $warehouse->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => $item->name, 'quantity' => '5', 'unit_price' => '10.00', 'vat_rate' => '0']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->call('confirm')
            ->assertHasErrors(['confirm']);

        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_recording_a_payment_and_cancel_button_is_hidden_once_paid(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01', 'status' => 'confirmed', 'fiscal_year' => 2026, 'invoice_number' => 1]);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $entry = \App\Models\JournalEntry::factory()->for($company)->create();
        $invoice->update(['journal_entry_id' => $entry->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->set('paymentAmount', '100.00')
            ->set('paymentDate', '2026-03-10')
            ->set('paymentMethod', 'bank')
            ->call('recordPayment')
            ->assertHasNoErrors()
            ->assertDontSee('Cancel invoice');

        $this->assertDatabaseHas('sales_invoice_payments', ['sales_invoice_id' => $invoice->id, 'amount' => '100.00']);
    }

    public function test_mark_sent_sets_sent_at(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'confirmed']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->call('markSent');

        $this->assertNotNull($invoice->fresh()->sent_at);
    }

    public function test_mark_sent_rejects_draft_invoices(): void
    {
        $company = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['status' => 'draft']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(SalesInvoiceShow::class, ['company' => $company, 'salesInvoice' => $invoice])
            ->call('markSent')
            ->assertHasErrors(['markSent']);

        $this->assertNull($invoice->fresh()->sent_at);
    }

    public function test_client_cannot_view_another_companys_invoice(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $invoice = SalesInvoice::factory()->for($otherCompany)->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(SalesInvoiceShow::class, ['company' => $otherCompany, 'salesInvoice' => $invoice])
            ->assertForbidden();
    }
}
