<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Services\Posting\SalesInvoicePostingContext;
use App\Support\Posting\ItemKind;
use App\Support\Posting\VatGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoicePostingContextTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(Company $company, array $attrs = []): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();

        return SalesInvoice::factory()->for($company)->create(array_merge(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01'], $attrs));
    }

    private function build(SalesInvoice $invoice, string $cogs = '0.00')
    {
        return SalesInvoicePostingContext::build($invoice->load('lines.item', 'company'), '2026/1', $cogs);
    }

    public function test_a_service_at_18_percent(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $context = $this->build($invoice);

        $this->assertSame('1180.00', $context->totals['ВКУПНО']);
        $this->assertSame('1000.00', $context->totals['ОСНОВИЦА']);
        $this->assertSame('180.00', $context->totals['ДДВ']);
        $this->assertSame('0.00', $context->totals['НАБАВНА_ВРЕДНОСТ']);
        $this->assertCount(1, $context->slices);
        $this->assertSame(ItemKind::SERVICE, $context->slices[0]->itemKind);
        $this->assertSame(VatGroup::GENERAL, $context->slices[0]->vatGroup);
        $this->assertSame('1000.00', $context->slices[0]->base);
        $this->assertSame('180.00', $context->slices[0]->vat);
        $this->assertFalse($context->flags['has_goods']);
        $this->assertNull($context->foreign);
        $this->assertSame('Invoice 2026/1', $context->documentLabel);
    }

    public function test_goods_and_services_in_different_groups_become_separate_slices(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $product = Item::factory()->for($company)->create(['type' => 'product']);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['item_id' => $product->id, 'description' => 'Стока', 'quantity' => '2', 'unit_price' => '100.00', 'vat_rate' => '5.00']);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '300.00', 'vat_rate' => '18.00']);
        $invoice->lines()->create(['description' => 'Извоз', 'quantity' => '1', 'unit_price' => '50.00', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        $context = $this->build($invoice, '120.00');

        $this->assertCount(3, $context->slices);
        $byKey = collect($context->slices)->keyBy(fn ($s) => $s->itemKind->value.'|'.$s->vatGroup->value);
        $this->assertSame('200.00', $byKey['goods|reduced']->base);
        $this->assertSame('10.00', $byKey['goods|reduced']->vat);
        $this->assertSame('300.00', $byKey['service|general']->base);
        $this->assertSame('54.00', $byKey['service|general']->vat);
        $this->assertSame('50.00', $byKey['service|export']->base);
        $this->assertSame('614.00', $context->totals['ВКУПНО']);
        $this->assertSame('120.00', $context->totals['НАБАВНА_ВРЕДНОСТ']);
        $this->assertTrue($context->flags['has_goods']);
    }

    public function test_a_company_that_is_not_vat_registered_has_no_vat(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $invoice = $this->invoice($company);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        $context = $this->build($invoice);

        $this->assertSame('1000.00', $context->totals['ВКУПНО']);
        $this->assertSame('0.00', $context->totals['ДДВ']);
        $this->assertSame('0.00', $context->slices[0]->vat);
    }

    public function test_a_foreign_invoice_closes_to_the_cent_and_keeps_the_foreign_amounts(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->invoice($company, ['currency' => 'EUR', 'exchange_rate' => '61.512345']);
        $invoice->lines()->create(['description' => 'A', 'quantity' => '1', 'unit_price' => '33.33', 'vat_rate' => '18.00']);
        $invoice->lines()->create(['description' => 'B', 'quantity' => '1', 'unit_price' => '66.67', 'vat_rate' => '5.00']);
        $invoice->lines()->create(['description' => 'C', 'quantity' => '1', 'unit_price' => '10.01', 'vat_rate' => '5.00']);

        $context = $this->build($invoice);

        $this->assertSame(['currency_code' => 'EUR', 'exchange_rate' => '61.512345'], $context->foreign);
        $baseSum = collect($context->slices)->reduce(fn ($c, $s) => bcadd($c, $s->base, 2), '0.00');
        $vatSum = collect($context->slices)->reduce(fn ($c, $s) => bcadd($c, $s->vat, 2), '0.00');
        $this->assertSame($context->totals['ОСНОВИЦА'], $baseSum);
        $this->assertSame($context->totals['ДДВ'], $vatSum);
        $this->assertSame($context->totals['ВКУПНО'], bcadd($baseSum, $vatSum, 2));
        // Вкупното е конвертирано ЕДНАШ, не збир од заокружени делови.
        $grossForeign = $invoice->grandTotal();
        $this->assertSame(
            \App\Support\Bcmath::roundHalfUp(bcmul($grossForeign, '61.512345', 10), 2),
            $context->totals['ВКУПНО']
        );
        $this->assertSame($grossForeign, $context->foreignTotals['ВКУПНО']);
        $this->assertSame($invoice->subtotal(), $context->foreignTotals['ОСНОВИЦА']);
    }
}
