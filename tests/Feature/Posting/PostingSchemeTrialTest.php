<?php

namespace Tests\Feature\Posting;

use App\Exceptions\PostingSchemeException;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\PostingSchemeEditor;
use App\Services\Posting\PostingSchemes;
use App\Services\Posting\PostingSchemeTrial;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingSchemeTrialTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedSale(Company $company): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '1000.00', 'vat_rate' => '18.00']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    private function confirmedPurchase(Company $company): PurchaseInvoice
    {
        $partner = Partner::factory()->for($company)->create(['name' => 'Добавувач']);
        $invoice = PurchaseInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'supplier_invoice_number' => '77', 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['account_id' => \App\Models\Account::where('company_id', $company->id)->where('code', '4620')->value('id'), 'description' => 'X', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);

        return app(PurchaseInvoiceService::class)->confirm($invoice->fresh(), User::factory()->create()->id);
    }

    private function draftScheme(Company $company, PostingDocType $type)
    {
        $scheme = PostingSchemes::for($company, $type);
        $draft = app(PostingSchemeEditor::class)->draftOf($scheme);

        return [app(PostingSchemeEditor::class)->transientScheme($company, $type, $scheme->name, $draft['rows'], $draft['matrix']), $draft];
    }

    public function test_the_documents_are_the_confirmed_invoices_of_the_company(): void
    {
        $company = Company::factory()->create();
        $sale = $this->confirmedSale($company);
        $this->confirmedSale(Company::factory()->create());
        SalesInvoice::factory()->for($company)->create(); // нацрт

        $documents = app(PostingSchemeTrial::class)->documents($company, PostingDocType::SALES_INVOICE);

        $this->assertCount(1, $documents);
        $this->assertSame($sale->id, $documents->first()['id']);
    }

    public function test_a_sales_invoice_trial_reproduces_the_posting_without_writing(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $sale = $this->confirmedSale($company);
        [$scheme] = $this->draftScheme($company, PostingDocType::SALES_INVOICE);
        $before = JournalEntry::count();

        $lines = app(PostingSchemeTrial::class)->run($company, PostingDocType::SALES_INVOICE, $sale->id, $scheme);

        $codes = collect($lines)->map(fn ($l) => $l->account->code.($l->side === 'debit' ? ' D ' : ' C ').$l->amount)->all();
        $this->assertEqualsCanonicalizing(['1200 D 1180.00', '74000 C 1000.00', '2300 C 180.00'], $codes);
        $this->assertSame($before, JournalEntry::count());
    }

    public function test_the_trial_uses_the_given_draft_not_the_saved_scheme(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $sale = $this->confirmedSale($company);
        [, $draft] = $this->draftScheme($company, PostingDocType::SALES_INVOICE);
        $draft['matrix'] = array_map(function ($m) {
            if ($m['matrix_key'] === 'revenue' && $m['item_kind'] === 'service' && $m['vat_group'] === 'general') {
                $m['account_code'] = '74001';
            }

            return $m;
        }, $draft['matrix']);
        $scheme = app(PostingSchemeEditor::class)->transientScheme($company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']);

        $lines = app(PostingSchemeTrial::class)->run($company, PostingDocType::SALES_INVOICE, $sale->id, $scheme);

        $this->assertContains('74001', collect($lines)->map(fn ($l) => $l->account->code)->all());
    }

    public function test_a_broken_draft_throws_the_engine_message(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $sale = $this->confirmedSale($company);
        [, $draft] = $this->draftScheme($company, PostingDocType::SALES_INVOICE);
        $draft['rows'][0]['formula'] = 'ВКУПНО + 1';
        $scheme = app(PostingSchemeEditor::class)->transientScheme($company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']);

        $this->expectException(PostingSchemeException::class);
        $this->expectExceptionMessage('не се балансира');

        app(PostingSchemeTrial::class)->run($company, PostingDocType::SALES_INVOICE, $sale->id, $scheme);
    }

    public function test_payments_and_purchase_invoices_can_be_tried(): void
    {
        $company = Company::factory()->create(['is_vat_registered' => true]);
        $sale = $this->confirmedSale($company);
        $purchase = $this->confirmedPurchase($company);
        $trial = app(PostingSchemeTrial::class);

        [$salesPayment] = $this->draftScheme($company, PostingDocType::SALES_PAYMENT);
        $lines = $trial->run($company, PostingDocType::SALES_PAYMENT, $sale->id, $salesPayment);
        $this->assertEqualsCanonicalizing(['1000 D 1180.00', '1200 C 1180.00'], collect($lines)->map(fn ($l) => $l->account->code.($l->side === 'debit' ? ' D ' : ' C ').$l->amount)->all());

        [$purchaseScheme] = $this->draftScheme($company, PostingDocType::PURCHASE_INVOICE);
        $lines = $trial->run($company, PostingDocType::PURCHASE_INVOICE, $purchase->id, $purchaseScheme);
        $this->assertEqualsCanonicalizing(['4620 D 100.00', '2200 C 100.00'], collect($lines)->map(fn ($l) => $l->account->code.($l->side === 'debit' ? ' D ' : ' C ').$l->amount)->all());

        [$purchasePayment] = $this->draftScheme($company, PostingDocType::PURCHASE_PAYMENT);
        $lines = $trial->run($company, PostingDocType::PURCHASE_PAYMENT, $purchase->id, $purchasePayment, true);
        $this->assertEqualsCanonicalizing(['2200 D 100.00', '1020 C 100.00'], collect($lines)->map(fn ($l) => $l->account->code.($l->side === 'debit' ? ' D ' : ' C ').$l->amount)->all());
    }

    public function test_a_document_of_another_company_is_not_found(): void
    {
        $company = Company::factory()->create();
        $foreign = $this->confirmedSale(Company::factory()->create());
        [$scheme] = $this->draftScheme($company, PostingDocType::SALES_INVOICE);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(PostingSchemeTrial::class)->run($company, PostingDocType::SALES_INVOICE, $foreign->id, $scheme);
    }
}
