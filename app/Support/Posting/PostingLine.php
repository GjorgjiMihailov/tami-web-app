<?php

namespace App\Support\Posting;

use App\Models\Account;

/** Една ставка од книжење што ја произвел моторот: сè уште не е запишана. */
final class PostingLine
{
    public function __construct(
        public readonly Account $account,
        public readonly string $side,
        public readonly string $amount,
        public readonly ?int $partnerId,
        public readonly string $description,
        public readonly ?string $foreignAmount = null,
        public readonly ?string $currencyCode = null,
        public readonly ?string $exchangeRate = null,
    ) {}

    /** Колони за `journal_entry_lines`. Девизните колони се само кога постојат. */
    public function journalColumns(mixed $lineDate): array
    {
        $columns = [
            'account_id' => $this->account->id,
            'partner_id' => $this->partnerId,
            'description' => $this->description,
            'line_date' => $lineDate,
            'debit' => $this->side === 'debit' ? $this->amount : '0',
            'credit' => $this->side === 'credit' ? $this->amount : '0',
        ];

        if ($this->foreignAmount !== null) {
            $columns['currency_code'] = $this->currencyCode;
            $columns['exchange_rate'] = $this->exchangeRate;
            $columns['foreign_amount'] = $this->foreignAmount;
        }

        return $columns;
    }
}
