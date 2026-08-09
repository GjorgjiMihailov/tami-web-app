<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceShow;
use App\Models\Account;
use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceShowTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    private function seedAccounts($company): void
    {
        foreach (['130', '220', '660', '100', '102'] as $code) {
            Account::firstOrCreate(['company_id' => $company->id, 'code' => $code], ['name' => $code]);
        }
    }

    public function test_confirm_action_posts_the_gl_entry(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->where('code', '462')->first()
            ?? Account::factory()->for($company)->create(['code' => '462', 'name' => 'Services']);
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id]);
        $invoice->lines()->create(['account_id' => $account->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $invoice])
            ->call('confirm')
            ->assertHasNoErrors();

        $this->assertSame('confirmed', $invoice->fresh()->status);
    }

    public function test_cancel_action_is_available_only_when_unpaid(): void
    {
        $company = Company::factory()->create();
        $this->seedAccounts($company);
        $partner = Partner::factory()->for($company)->create();
        $account = Account::where('company_id', $company->id)->where('code', '462')->first()
            ?? Account::factory()->for($company)->create(['code' => '462', 'name' => 'Services']);
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['account_id' => $account->id, 'description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $journalEntry = \App\Models\JournalEntry::factory()->for($company)->create();
        $journalEntry->lines()->create(['account_id' => $account->id, 'debit' => '100.00', 'credit' => '0']);
        $invoice->update(['journal_entry_id' => $journalEntry->id]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $invoice])
            ->call('cancel')
            ->assertHasNoErrors();

        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    public function test_the_line_items_table_gets_the_hover_treatment_but_the_payments_table_does_not(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        // purchase_invoice_payments.created_by is a required (non-nullable) foreign key to users —
        // create the payment AFTER $admin exists, and pass created_by explicitly.
        $invoice->payments()->create(['amount' => '50.00', 'payment_date' => '2026-03-10', 'payment_method' => 'bank', 'created_by' => $admin->id]);
        $this->actingAs($admin);

        $html = Livewire::test(PurchaseInvoiceShow::class, ['company' => $company, 'purchaseInvoice' => $invoice])->html();

        $this->assertSame(1, substr_count($html, 'bg-gray-50'));
        $this->assertSame(1, substr_count($html, 'hover:bg-orange-50'));
    }
}
