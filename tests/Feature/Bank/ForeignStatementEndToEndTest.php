<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Bank\BankStatementPoster;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\BankStatementKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Пример од стариот софтвер: Nordhealth Finland Oy, 6.900 EUR. */
class ForeignStatementEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private function balance(string $code): string
    {
        return JournalEntryLine::whereHas('account', fn ($q) => $q->where('company_id', $this->company->id)->where('code', $code))
            ->get()->reduce(fn (string $c, $l) => bcsub(bcadd($c, (string) $l->debit, 2), (string) $l->credit, 2), '0.00');
    }

    private function foreignBalance(string $code): string
    {
        return JournalEntryLine::whereHas('account', fn ($q) => $q->where('company_id', $this->company->id)->where('code', $code))
            ->whereNotNull('foreign_amount')
            ->get()->reduce(fn (string $c, $l) => bccomp((string) $l->debit, '0', 2) > 0
                ? bcadd($c, (string) $l->foreign_amount, 2)
                : bcsub($c, (string) $l->foreign_amount, 2), '0.00');
    }

    public function test_the_whole_flow_closes_receivable_1030_and_1039(): void
    {
        $this->company = Company::factory()->create(['type' => 'legal', 'is_vat_registered' => false]);
        $this->user = User::factory()->create();
        $partner = Partner::factory()->for($this->company)->create();
        $account = fn (string $code) => Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();

        // Фактура 6.900 EUR на услуга по курс 61,50 → 424.350 ден на 1211.
        $invoice = SalesInvoice::factory()->for($this->company)->create([
            'partner_id' => $partner->id, 'invoice_date' => '2026-10-01', 'currency' => 'EUR', 'exchange_rate' => '61.500000',
        ]);
        $invoice->lines()->create(['description' => 'Услуга', 'quantity' => '1', 'unit_price' => '6900.00', 'vat_rate' => '0.00', 'vat_treatment' => 'export']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $this->user->id);

        // Девизен извод: прилив по 61,40 (загуба 690 на 4750), па продажба на девиза по 61,5027.
        $foreign = BankStatement::factory()->for($this->company)->create([
            'kind' => BankStatementKind::FOREIGN, 'currency' => 'EUR', 'statement_date' => '2026-10-20', 'number' => 10,
            'opening_balance' => '0.00', 'closing_balance' => '0.00',
        ]);
        BankStatementLine::factory()->for($foreign)->create([
            'line_date' => '2026-10-20', 'direction' => LineDirection::IN, 'amount' => '6900.00', 'exchange_rate' => '61.400000',
            'kind' => LineKind::INVOICE_PAYMENT, 'sales_invoice_id' => $invoice->id, 'partner_id' => $partner->id, 'position' => 1,
        ]);
        BankStatementLine::factory()->for($foreign)->create([
            'line_date' => '2026-10-20', 'direction' => LineDirection::OUT, 'amount' => '6900.00', 'exchange_rate' => '61.502700',
            'kind' => LineKind::CURRENCY_SALE, 'position' => 2,
        ]);
        $foreignEntry = app(BankStatementPoster::class)->post($foreign->fresh(), $this->user->id);

        // Денарски извод: банката исплатила 424.338,96 за 6.900 EUR; системот ја предлага разликата 29,67.
        $denar = BankStatement::factory()->for($this->company)->create([
            'kind' => BankStatementKind::DENAR, 'statement_date' => '2026-10-25', 'number' => 11,
            'opening_balance' => '0.00', 'closing_balance' => '424338.96',
        ]);
        BankStatementLine::factory()->for($denar)->create([
            'line_date' => '2026-10-25', 'direction' => LineDirection::IN, 'kind' => LineKind::ACCOUNT,
            'account_id' => $account('1039')->id, 'amount' => '424338.96', 'foreign_amount' => '6900.00', 'currency' => 'EUR',
            'settle_difference' => true, 'position' => 1,
        ]);
        $denarEntry = app(BankStatementPoster::class)->post($denar->fresh(), $this->user->id);

        $this->assertSame('0.00', $this->balance('1211'));
        $this->assertSame('0.00', $invoice->fresh()->balanceDue());
        $this->assertSame('0.00', $this->foreignBalance('1030'));
        // Денарите на 1030 остануваат (прилив 423.660 − продажба 424.368,63), како што одлучи сопственикот.
        $this->assertSame('-708.63', $this->balance('1030'));
        $this->assertSame('0.00', $this->balance('1039'));
        $this->assertSame('0.00', $this->foreignBalance('1039'));
        $this->assertSame('719.67', $this->balance('4750'));
        $this->assertSame('424338.96', $this->balance('1000'));

        foreach (JournalEntry::where('company_id', $this->company->id)->get() as $entry) {
            $this->assertSame((string) $entry->lines()->sum('debit'), (string) $entry->lines()->sum('credit'), "Налог {$entry->id} не балансира");
        }

        $this->assertSame('11', $foreignEntry->journalGroup->code);
        $this->assertSame('10', $denarEntry->journalGroup->code);
    }
}
