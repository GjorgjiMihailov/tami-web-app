<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\SalesInvoicePayment;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\PostingSchemes;
use App\Support\Bcmath;
use App\Support\JournalGroupCodes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Фирми чија шема не ги знае девизните редови: фактурата стои на 1200, наплатата мора да ја затвори таму. */
class ForeignReceiptFallbackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private function oldSchemeCompany(): Company
    {
        $company = Company::factory()->create(['is_vat_registered' => false]);
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $scheme->rows()->whereIn('formula', ['ВКУПНО_ДОБРА', 'ВКУПНО_УСЛУГИ'])->delete();
        $scheme->rows()->where('formula', 'ВКУПНО')->update(['condition' => null]);

        return $company;
    }

    private function confirmed(Company $company, string $price, string $rate): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create();
        $this->user = User::factory()->create();
        $invoice = SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id, 'invoice_date' => '2026-10-01', 'currency' => 'EUR', 'exchange_rate' => $rate,
        ]);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => $price, 'vat_rate' => '0.00', 'vat_treatment' => 'export']);

        return app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);
    }

    private function balance(Company $company, string $code): string
    {
        return JournalEntryLine::whereHas('account', fn ($q) => $q->where('company_id', $company->id)->where('code', $code))
            ->get()
            ->reduce(fn (string $c, $l) => bcsub(bcadd($c, (string) $l->debit, 2), (string) $l->credit, 2), '0.00');
    }

    public function test_an_invoice_on_1200_is_closed_there_by_three_partial_payments(): void
    {
        $company = $this->oldSchemeCompany();
        $invoice = $this->confirmed($company, '1000.00', '61.473300');
        $service = app(SalesInvoiceService::class);

        $service->recordPayment($invoice->fresh(), '333.33', '2026-10-10', 'bank', $this->user->id, '61.200000');
        $service->recordPayment($invoice->fresh(), '333.33', '2026-10-11', 'bank', $this->user->id, '61.900000');
        $service->recordPayment($invoice->fresh(), '333.34', '2026-10-12', 'bank', $this->user->id, '61.473300');

        $this->assertSame('0.00', $this->balance($company, '1200'));
        $this->assertSame('0.00', $this->balance($company, '1211'));

        foreach (JournalEntry::where('company_id', $company->id)->with('lines')->get() as $entry) {
            $this->assertSame(0, bccomp($entry->lines->sum('debit'), $entry->lines->sum('credit'), 2));
        }
    }

    public function test_an_invoice_part_paid_before_the_foreign_accounts_still_closes_to_exactly_zero(): void
    {
        $company = $this->oldSchemeCompany();
        $invoice = $this->confirmed($company, '402.48', '61.258861');

        // Старата наплата: износ × курс на фактурата, заокружено на кумулативот; без курс на плаќањето.
        $legacyMkd = Bcmath::roundHalfUp(bcmul('119.58', '61.258861', 10), 2);
        SalesInvoicePayment::create([
            'sales_invoice_id' => $invoice->id, 'amount' => '119.58', 'payment_date' => '2026-10-05',
            'payment_method' => 'bank', 'created_by' => $this->user->id,
        ]);
        $entry = JournalEntry::create([
            'company_id' => $company->id, 'journal_group_id' => JournalGroupCodes::group($company, JournalGroupCodes::DENAR_BANK)->id,
            'entry_date' => '2026-10-05', 'description' => 'Стара наплата', 'created_by' => $this->user->id,
        ]);
        $entry->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '1000')->value('id'), 'partner_id' => $invoice->partner_id, 'line_date' => '2026-10-05', 'debit' => $legacyMkd, 'credit' => '0', 'description' => 'Стара наплата']);
        $entry->lines()->create(['account_id' => Account::where('company_id', $company->id)->where('code', '1200')->value('id'), 'partner_id' => $invoice->partner_id, 'line_date' => '2026-10-05', 'debit' => '0', 'credit' => $legacyMkd, 'description' => 'Стара наплата']);

        app(SalesInvoiceService::class)->recordPayment($invoice->fresh(), '282.90', '2026-10-20', 'bank', $this->user->id, '61.258861');

        $this->assertSame('0.00', $this->balance($company, '1200'));
    }
}
