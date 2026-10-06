<?php

namespace App\Services\Bank;

use App\Exceptions\InvalidBankStatementException;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Services\Invoicing\PurchaseInvoiceService;
use App\Services\Invoicing\SalesInvoiceService;
use App\Services\Posting\PostedInvoiceAccounts;
use App\Support\Bank\BankAccountGroups;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\StatementControls;
use Illuminate\Support\Facades\DB;

/**
 * Еден потврден извод е еден налог. Почетната состојба не се книжи — само
 * движењето; контролата на состојбите е во StatementControls.
 */
class BankStatementPoster
{
    public function __construct(
        private readonly SalesInvoiceService $sales,
        private readonly PurchaseInvoiceService $purchases,
    ) {}

    public function post(BankStatement $statement, int $userId): JournalEntry
    {
        return DB::transaction(function () use ($statement, $userId) {
            $statement = BankStatement::whereKey($statement->id)->lockForUpdate()->with('lines')->firstOrFail();

            if ($statement->isBooked()) {
                throw new InvalidBankStatementException('Изводот е веќе прокнижен.');
            }

            $problems = StatementControls::problems($statement);

            if ($problems !== []) {
                throw new InvalidBankStatementException(implode("\n", $problems));
            }

            $bank = $this->account($statement, Account::BANK_CODE);

            $entry = JournalEntry::create([
                'company_id' => $statement->company_id,
                'journal_group_id' => BankAccountGroups::groupFor($statement)->id,
                'entry_date' => $statement->statement_date,
                'description' => "Извод бр. {$statement->number} — {$statement->bank} {$statement->account}",
                'created_by' => $userId,
            ]);

            foreach ($statement->lines as $line) {
                $this->postLine($statement, $entry, $bank, $line, $userId);
            }

            $statement->update(['status' => BankStatement::STATUS_BOOKED, 'journal_entry_id' => $entry->id]);

            return $entry;
        });
    }

    /**
     * Го враќа изводот во нацрт: го брише налогот и плаќањата што ги создале
     * неговите ставки (врзаните претходни плаќања остануваат). Само најновиот
     * книжен извод на сметката — инаку би се скршила низата на состојби.
     */
    public function reopen(BankStatement $statement): void
    {
        DB::transaction(function () use ($statement) {
            $statement = BankStatement::whereKey($statement->id)->lockForUpdate()->with('lines')->firstOrFail();

            if (! $statement->isBooked()) {
                throw new InvalidBankStatementException('Изводот не е прокнижен.');
            }

            $later = BankStatement::where('company_id', $statement->company_id)
                ->where('account', $statement->account)
                ->where('kind', $statement->kind)
                ->where('status', BankStatement::STATUS_BOOKED)
                ->whereYear('statement_date', $statement->statement_date->year)
                ->where('number', '>', $statement->number)
                ->exists();

            if ($later) {
                throw new InvalidBankStatementException('Прво отворете ги подоцнежните изводи на оваа сметка.');
            }

            foreach ($statement->lines as $line) {
                if (! $line->created_payment) {
                    continue;
                }

                $payment = $line->salesInvoicePayment ?? $line->purchaseInvoicePayment;
                $line->update(['sales_invoice_payment_id' => null, 'purchase_invoice_payment_id' => null, 'created_payment' => false]);
                $payment?->delete();
            }

            $entry = $statement->journalEntry;
            $statement->update(['status' => BankStatement::STATUS_DRAFT, 'journal_entry_id' => null]);
            $entry?->delete();
        });
    }

    private function postLine(BankStatement $statement, JournalEntry $entry, Account $bank, BankStatementLine $line, int $userId): void
    {
        $isIn = $line->direction === LineDirection::IN;
        $partnerId = $line->partner_id;

        if ($line->kind === LineKind::INVOICE_PAYMENT) {
            $existing = $isIn ? $line->sales_invoice_payment_id : $line->purchase_invoice_payment_id;

            if ($existing !== null) {
                // Веќе книжено со свој налог (на 1000) — второ книжење би го удвоило.
                return;
            }

            // Свеж запис: фактурата закачена на ставката е веќе вчитана од
            // проверката со стар список на плаќања, па втора ставка на истата
            // фактура би ја преплатила.
            $invoice = $isIn
                ? $line->salesInvoice()->firstOrFail()
                : $line->purchaseInvoice()->firstOrFail();
            $partnerId = $invoice->partner_id;
            $date = $line->line_date->toDateString();

            $payment = $isIn
                ? $this->sales->createPaymentRecord($invoice, (string) $line->amount, $date, $userId)
                : $this->purchases->createPaymentRecord($invoice, (string) $line->amount, $date, $userId);

            $line->update($isIn
                ? ['sales_invoice_payment_id' => $payment->id, 'created_payment' => true]
                : ['purchase_invoice_payment_id' => $payment->id, 'created_payment' => true]);

            // Уплата од купувач ја затвора сметката на којашто фактурата го
            // отворила побарувањето (1200, или 120 за стара фактура); исплата
            // кон добавувач — онаму каде што е отворена обврската (2200/2210,
            // или 220 за стара фактура).
            $counter = $isIn
                ? PostedInvoiceAccounts::receivable($invoice)
                : PostedInvoiceAccounts::payable($invoice);
        } else {
            $counter = Account::where('company_id', $statement->company_id)->findOrFail($line->account_id);
        }

        $label = $line->description ?: "Извод бр. {$statement->number}";
        $amount = (string) $line->amount;
        $common = ['partner_id' => $partnerId, 'description' => $label, 'line_date' => $line->line_date];

        $entry->lines()->create($common + ['account_id' => $bank->id, 'debit' => $isIn ? $amount : '0', 'credit' => $isIn ? '0' : $amount]);
        $entry->lines()->create($common + ['account_id' => $counter->id, 'debit' => $isIn ? '0' : $amount, 'credit' => $isIn ? $amount : '0']);
    }

    private function account(BankStatement $statement, string $code): Account
    {
        return Account::where('company_id', $statement->company_id)->where('code', $code)->firstOrFail();
    }
}
