<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceSchemePostingTest extends TestCase
{
    use RefreshDatabase;

    private function draft(Company $company, array $attrs = []): PurchaseInvoice
    {
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач']);

        return PurchaseInvoice::factory()->for($company)->create(array_merge([
            'partner_id' => $partner->id, 'supplier_invoice_number' => '77', 'invoice_date' => '2026-03-01',
        ], $attrs));
    }

    private function expenseId(Company $company): int
    {
        return Account::where('company_id', $company->id)->where('code', '4620')->value('id');
    }

    private function codes(PurchaseInvoice $confirmed): array
    {
        return $confirmed->journalEntry->lines()->with('account')->get()
            ->map(fn ($l) => $l->account->code.($l->debit > 0 ? ' D ' : ' C ').($l->debit > 0 ? $l->debit : $l->credit))
            ->all();
    }

    private function confirm(PurchaseInvoice $invoice): PurchaseInvoice
    {
        return app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    public function test_an_expense_bill_posts_to_the_chosen_account_1300_and_2200(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company);
        $invoice->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'Консалтинг', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $this->assertEqualsCanonicalizing(['4620 D 1000.00', '1300 D 180.00', '2200 C 1180.00'], $this->codes($this->confirm($invoice)));
    }

    public function test_the_reduced_rate_goes_to_1301(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company);
        $invoice->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'X', 'quantity' => '1', 'unit_price' => '200.00', 'vat_rate' => '5.00']);

        $this->assertEqualsCanonicalizing(['4620 D 200.00', '1301 D 10.00', '2200 C 210.00'], $this->codes($this->confirm($invoice)));
    }

    public function test_domestic_goods_go_to_6600(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['type' => 'product']);
        $invoice = $this->draft($company, ['warehouse_id' => $warehouse->id]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'Стока', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);

        $this->assertEqualsCanonicalizing(['6600 D 500.00', '1300 D 90.00', '2200 C 590.00'], $this->codes($this->confirm($invoice)));
    }

    public function test_import_goods_go_to_6601_vat_to_1302_and_the_debt_to_2210(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['type' => 'product']);
        $invoice = $this->draft($company, ['warehouse_id' => $warehouse->id, 'is_import' => true]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'Стока', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '18.00']);

        $this->assertEqualsCanonicalizing(['6601 D 500.00', '1302 D 90.00', '2210 C 590.00'], $this->codes($this->confirm($invoice)));
    }

    public function test_non_deductible_vat_stays_in_the_cost(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company);
        $invoice->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'Репрезентација', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_deductible' => false]);

        $this->assertEqualsCanonicalizing(['4620 D 1180.00', '2200 C 1180.00'], $this->codes($this->confirm($invoice)));
    }

    public function test_the_entry_keeps_group_99_the_description_the_partner_and_the_labels(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company);
        $invoice->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $confirmed = $this->confirm($invoice);
        $entry = $confirmed->journalEntry()->with('lines', 'journalGroup')->first();

        $this->assertSame('99', $entry->journalGroup->code);
        $this->assertSame('Purchase bill Добавувач #77', $entry->description);
        $this->assertTrue($entry->lines->every(fn ($l) => $l->partner_id === $invoice->partner_id));
        $this->assertTrue($entry->lines->contains(fn ($l) => $l->description === 'Input VAT on Purchase bill Добавувач #77'));
    }

    public function test_changing_the_supplier_number_still_renames_the_lines(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company);
        $invoice->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $service = app(PurchaseInvoiceService::class);
        $confirmed = $service->confirm($invoice->fresh(), User::factory()->create()->id);

        $service->changeSupplierNumber($confirmed, '99', User::factory()->create()->id);

        $this->assertTrue($confirmed->fresh()->journalEntry->lines->every(fn ($l) => str_contains($l->description, '#99')));
    }

    public function test_a_changed_scheme_changes_the_next_posting_only(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $first = $this->draft($company);
        $first->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);
        $confirmedFirst = $this->confirm($first);
        $scheme = PostingSchemes::for($company, PostingDocType::PURCHASE_INVOICE);
        $other = Account::where('company_id', $company->id)->where('code', '1301')->firstOrFail();
        $scheme->matrixAccounts()->where('matrix_key', 'input_vat')->where('vat_group', 'general')->update(['account_id' => $other->id]);
        $second = $this->draft($company, ['supplier_invoice_number' => '78']);
        $second->lines()->create(['account_id' => $this->expenseId($company), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        $this->assertContains('1300 D 18.00', $this->codes($confirmedFirst->fresh()));
        $this->assertContains('1301 D 18.00', $this->codes($this->confirm($second)));
    }
}
