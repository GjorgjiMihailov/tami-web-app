<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceShow;
use App\Models\Account;
use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_it_downloads_the_attached_source_document(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $path = "purchase-invoices/{$company->id}/1/bill.pdf";
        Storage::disk('google')->put($path, 'fake-pdf-content');
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'source_document_path' => $path]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('purchase-invoices.document', [$company, $invoice]));

        $response->assertOk();
    }

    public function test_document_download_requires_view_permission(): void
    {
        Storage::fake('google');
        Storage::disk('google')->put('purchase-invoices/1/1/bill.pdf', 'fake-pdf-content');

        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($otherCompany)->create(['source_document_path' => 'purchase-invoices/1/1/bill.pdf']);
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get(route('purchase-invoices.document', [$otherCompany, $invoice]))->assertForbidden();
    }
}
