<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Services\Posting\PurchaseInvoicePostingContext;
use App\Support\Posting\ItemKind;
use App\Support\Posting\VatGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoicePostingContextTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(Company $company, array $attrs = []): PurchaseInvoice
    {
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач']);

        return PurchaseInvoice::factory()->for($company)->create(array_merge(['partner_id' => $partner->id, 'supplier_invoice_number' => '77', 'invoice_date' => '2026-03-01'], $attrs));
    }

    private function expense(Company $company, string $code = '462'): Account
    {
        return Account::where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }

    private function build(PurchaseInvoice $invoice)
    {
        return PurchaseInvoicePostingContext::build($invoice->load('lines.item', 'lines.account', 'partner', 'company'));
    }

    public function test_an_expense_line_with_deductible_vat(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['account_id' => $this->expense($company)->id, 'description' => 'Консалтинг', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $context = $this->build($invoice);

        $this->assertSame('1180.00', $context->totals['ВКУПНО']);
        $this->assertSame('0.00', $context->totals['ЗАЛИХА']);
        $this->assertSame('1000.00', $context->totals['ТРОШОК_СТАВКА']);
        $this->assertSame('180.00', $context->totals['ОДБИВЛИВ_ДДВ']);
        $this->assertCount(1, $context->accountBuckets);
        $this->assertSame('462', $context->accountBuckets[0]['account']->code);
        $this->assertSame('1000.00', $context->accountBuckets[0]['amount']);
        $this->assertCount(1, $context->slices);
        $this->assertSame(ItemKind::SERVICE, $context->slices[0]->itemKind);
        $this->assertSame(VatGroup::GENERAL, $context->slices[0]->vatGroup);
        $this->assertSame('180.00', $context->slices[0]->vat);
        $this->assertFalse($context->flags['has_goods']);
        $this->assertFalse($context->flags['import']);
        $this->assertSame('Purchase bill Добавувач #77', $context->documentLabel);
        $this->assertSame($invoice->partner_id, $context->partnerId);
    }

    public function test_a_stock_line_goes_to_the_stock_variable_and_two_lines_on_one_account_merge(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $item = Item::factory()->for($company)->create(['type' => 'product']);
        $invoice = $this->invoice($company, ['is_import' => true]);
        $invoice->lines()->create(['item_id' => $item->id, 'description' => 'Стока', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '5.00']);
        $invoice->lines()->create(['account_id' => $this->expense($company)->id, 'description' => 'A', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        $invoice->lines()->create(['account_id' => $this->expense($company)->id, 'description' => 'B', 'quantity' => '1', 'unit_price' => '40.00', 'vat_rate' => '0']);

        $context = $this->build($invoice);

        $this->assertSame('500.00', $context->totals['ЗАЛИХА']);
        $this->assertSame('140.00', $context->totals['ТРОШОК_СТАВКА']);
        $this->assertSame('25.00', $context->totals['ОДБИВЛИВ_ДДВ']);
        $this->assertSame('665.00', $context->totals['ВКУПНО']);
        $this->assertCount(1, $context->accountBuckets);
        $this->assertSame('140.00', $context->accountBuckets[0]['amount']);
        $this->assertSame(ItemKind::GOODS, $context->slices[0]->itemKind);
        $this->assertSame(VatGroup::REDUCED, $context->slices[0]->vatGroup);
        $this->assertTrue($context->flags['has_goods']);
        $this->assertTrue($context->flags['import']);
    }

    public function test_non_deductible_vat_is_part_of_the_cost(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['account_id' => $this->expense($company)->id, 'description' => 'Репрезентација', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00', 'vat_deductible' => false]);

        $context = $this->build($invoice);

        $this->assertSame('1180.00', $context->accountBuckets[0]['amount']);
        $this->assertSame('0.00', $context->totals['ОДБИВЛИВ_ДДВ']);
        $this->assertSame('1180.00', $context->totals['ВКУПНО']);
        $this->assertSame([], $context->slices);
    }

    public function test_a_company_that_is_not_vat_registered_has_no_vat(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['account_id' => $this->expense($company)->id, 'description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $context = $this->build($invoice);

        $this->assertSame('1000.00', $context->totals['ВКУПНО']);
        $this->assertSame('0.00', $context->totals['ОДБИВЛИВ_ДДВ']);
        $this->assertSame([], $context->slices);
    }
}
