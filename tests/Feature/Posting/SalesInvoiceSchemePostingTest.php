<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceSchemePostingTest extends TestCase
{
    use RefreshDatabase;

    private function draft(Company $company, string $price, string $rate, array $lineExtra = []): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(array_merge(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => $rate], $lineExtra));

        return $invoice;
    }

    private function codes(SalesInvoice $confirmed): array
    {
        return $confirmed->journalEntry->lines()->with('account')->get()
            ->map(fn ($l) => $l->account->code.($l->debit > 0 ? ' D ' : ' C ').($l->debit > 0 ? $l->debit : $l->credit))
            ->all();
    }

    public function test_a_service_invoice_posts_to_1200_74000_and_2300(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company, '1000.00', '18.00');

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);

        $this->assertEqualsCanonicalizing(['1200 D 1180.00', '74000 C 1000.00', '2300 C 180.00'], $this->codes($confirmed));
    }

    public function test_a_goods_invoice_posts_to_74100_and_the_cost_of_goods(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $warehouse = Warehouse::factory()->for($company)->create();
        $item = Item::factory()->for($company)->create(['type' => 'product']);
        app(\App\Services\Inventory\StockMovementService::class)->receipt($item, $warehouse, '10', '60.00', '2026-02-01', User::factory()->create()->id);
        $invoice = $this->draft($company, '100.00', '18.00', ['item_id' => $item->id, 'quantity' => '5']);
        $invoice->update(['warehouse_id' => $warehouse->id]);

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);

        $this->assertEqualsCanonicalizing(
            ['1200 D 590.00', '74100 C 500.00', '2300 C 90.00', '7010 D 300.00', '6600 C 300.00'],
            $this->codes($confirmed)
        );
    }

    public function test_the_reduced_rate_goes_to_the_reduced_accounts(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company, '200.00', '5.00');

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);

        $this->assertEqualsCanonicalizing(['1200 D 210.00', '74001 C 200.00', '2301 C 10.00'], $this->codes($confirmed));
    }

    public function test_the_entry_keeps_group_99_the_description_and_the_labels(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company, '100.00', '18.00');

        $confirmed = app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
        $entry = $confirmed->journalEntry()->with('lines', 'journalGroup')->first();

        $this->assertSame('99', $entry->journalGroup->code);
        $this->assertStringStartsWith('Sales Invoice ', $entry->description);
        $this->assertTrue($entry->lines->contains(fn ($l) => str_starts_with($l->description, 'VAT on Invoice ')));
        $this->assertTrue($entry->lines->every(fn ($l) => str_contains($l->description, 'Invoice '.$confirmed->invoice_number_formatted)));
    }

    public function test_changing_the_number_still_renames_the_lines(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $invoice = $this->draft($company, '100.00', '18.00');
        $service = app(SalesInvoiceService::class);
        $confirmed = $service->confirm($invoice->fresh(), User::factory()->create()->id);

        $service->changeNumber($confirmed, 'XYZ-9', User::factory()->create()->id);

        $this->assertTrue($confirmed->fresh()->journalEntry->lines->every(fn ($l) => str_contains($l->description, 'Invoice XYZ-9')));
    }

    public function test_a_changed_scheme_changes_the_next_posting_only(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $service = app(SalesInvoiceService::class);
        $user = User::factory()->create();
        $first = $service->confirm($this->draft($company, '100.00', '18.00')->fresh(), $user->id);
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $other = \App\Models\Account::where('company_id', $company->id)->where('code', '74001')->firstOrFail();
        $scheme->matrixAccounts()->where('matrix_key', 'revenue')->where('item_kind', 'service')->where('vat_group', 'general')->update(['account_id' => $other->id]);

        $second = $service->confirm($this->draft($company, '100.00', '18.00')->fresh(), $user->id);

        $this->assertContains('74000 C 100.00', $this->codes($first->fresh()));
        $this->assertContains('74001 C 100.00', $this->codes($second));
    }
}
