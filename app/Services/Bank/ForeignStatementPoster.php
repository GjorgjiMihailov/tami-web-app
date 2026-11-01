<?php

namespace App\Services\Bank;

use App\Exceptions\InvalidBankStatementException;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Services\Invoicing\SalesInvoiceService;
use App\Support\Bank\BankAccountGroups;
use App\Support\Bank\LineKind;
use App\Support\Bcmath;
use App\Support\Posting\PostingLine;

/**
 * Налогот на еден девизен извод. Наплатата на фактура ја задолжува 1030 по
 * курсот на денот (види ForeignReceiptPosting); продажбата на девиза ја
 * побарува 1030 и ја задолжува 1039 по ист курс, па нема курсна разлика.
 *
 * Се повикува од BankStatementPoster::post, во неговата транзакција — изводот
 * е веќе заклучен, непрокнижен и со поминати проверки.
 */
class ForeignStatementPoster
{
    private const BANK = '1030';

    private const CONVERSION = '1039';

    public function __construct(private readonly SalesInvoiceService $sales) {}

    public function book(BankStatement $statement, int $userId): JournalEntry
    {
        $bank = $this->account($statement, self::BANK);
        $conversion = $this->account($statement, self::CONVERSION);

        $entry = JournalEntry::create([
            'company_id' => $statement->company_id,
            'journal_group_id' => BankAccountGroups::groupFor($statement)->id,
            'entry_date' => $statement->statement_date,
            'description' => "Девизен извод бр. {$statement->number} — {$statement->bank} {$statement->account}",
            'created_by' => $userId,
        ]);

        foreach ($statement->lines as $line) {
            $lines = $line->kind === LineKind::CURRENCY_SALE
                ? $this->conversionLines($statement, $line, $bank, $conversion)
                : $this->receiptLines($statement, $line, $userId);

            foreach ($lines as $posting) {
                $entry->lines()->create($posting->journalColumns($line->line_date));
            }
        }

        $statement->update(['status' => BankStatement::STATUS_BOOKED, 'journal_entry_id' => $entry->id]);

        return $entry;
    }

    /** @return list<PostingLine> */
    private function receiptLines(BankStatement $statement, BankStatementLine $line, int $userId): array
    {
        if ($line->sales_invoice_payment_id !== null) {
            // Веќе книжено со свој налог (копчето „Наплата“ на фактурата) — второ книжење би го удвоило.
            return [];
        }

        // Свеж запис: фактурата закачена на ставката може да е веќе вчитана со стар список на плаќања.
        $invoice = $line->salesInvoice()->firstOrFail();
        $label = $line->description ?: "Девизен извод бр. {$statement->number}";

        [$payment, $lines] = $this->sales->createForeignPaymentRecord(
            $invoice,
            (string) $line->amount,
            $line->line_date->toDateString(),
            (string) $line->exchange_rate,
            $userId,
            $label,
        );

        $line->update(['sales_invoice_payment_id' => $payment->id, 'created_payment' => true]);

        return $lines;
    }

    /** @return list<PostingLine> */
    private function conversionLines(BankStatement $statement, BankStatementLine $line, Account $bank, Account $conversion): array
    {
        $rate = (string) $line->exchange_rate;
        $amount = (string) $line->amount;
        $mkd = Bcmath::roundHalfUp(bcmul($amount, $rate, 10), 2);
        $label = $line->description ?: "Девизен извод бр. {$statement->number} — продажба на девиза";

        return [
            new PostingLine($conversion, 'debit', $mkd, null, $label, $amount, $statement->currency, $rate),
            new PostingLine($bank, 'credit', $mkd, null, $label, $amount, $statement->currency, $rate),
        ];
    }

    private function account(BankStatement $statement, string $code): Account
    {
        $account = Account::where('company_id', $statement->company_id)->analytical()->where('code', $code)->first();

        if ($account === null) {
            throw new InvalidBankStatementException("Фирмата нема аналитичко конто {$code}, потребно за девизен извод.");
        }

        return $account;
    }
}
