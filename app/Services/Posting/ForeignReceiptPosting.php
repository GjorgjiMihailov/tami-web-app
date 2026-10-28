<?php

namespace App\Services\Posting;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\SalesInvoice;
use App\Support\Bcmath;
use App\Support\Posting\PostingLine;

/**
 * Ставките на една наплата на девизна фактура (прилив на девизна сметка).
 *
 * Не оди низ шемите: курсната разлика и повеќето побарувања по една уплата
 * (1210 и 1211 на иста фактура) не се изразуваат со редовите на шемата, па
 * сметките 1030, 4750 и 7750 се константи. Побарувањето се затвора по курсот
 * на фактурата, а девизната сметка се задолжува по курсот на денот на приливот.
 */
class ForeignReceiptPosting
{
    private const BANK = '1030';

    private const LOSS = '4750';

    private const GAIN = '7750';

    /**
     * @param  string  $amount  износ на наплатата во валутата на фактурата
     * @param  string  $paidBefore  веќе наплатено до сега, во валутата на фактурата
     * @param  string  $rate  курс на денот на приливот
     * @param  bool  $paidBeforeAtInvoiceRate  претходните наплати се книжени по старото правило (износ × курс на фактурата, на 1200), па затворено до сега е тоа, не пропорцијата
     * @return list<PostingLine>
     */
    public function lines(SalesInvoice $invoice, string $amount, string $paidBefore, string $rate, string $label, bool $paidBeforeAtInvoiceRate = false): array
    {
        $receivables = PostedInvoiceAccounts::receivables($invoice);
        $foreignTotal = array_reduce($receivables, fn (string $c, array $r) => bcadd($c, $r['foreign'], 2), '0.00');

        if ($receivables === [] || bccomp($foreignTotal, '0', 2) <= 0) {
            throw new InvalidInvoiceStateException('Фактурата нема книжено девизно побарување за да се затвори.');
        }

        $bank = $this->account($invoice, self::BANK);
        $loss = $this->account($invoice, self::LOSS);
        $gain = $this->account($invoice, self::GAIN);

        $paidAfter = bcadd($paidBefore, $amount, 2);
        $inflow = Bcmath::roundHalfUp(bcmul($amount, $rate, 10), 2);
        $invoiceRate = (string) $invoice->exchange_rate;

        $credits = [];
        $closed = '0.00';

        foreach ($receivables as $receivable) {
            // Наплатите од пред девизните конта оделе по износ × курс на фактурата
            // (само на едно конто): тоа е веќе затворено, пропорцијата може да се
            // разликува за стотинка и би оставила остаток на побарувањето.
            $closedBefore = $paidBeforeAtInvoiceRate && count($receivables) === 1
                ? Bcmath::roundHalfUp(bcmul($paidBefore, $invoiceRate, 10), 2)
                : $this->share($receivable['debit'], $paidBefore, $foreignTotal);
            $credit = bcsub($this->share($receivable['debit'], $paidAfter, $foreignTotal), $closedBefore, 2);
            $foreignCredit = bcsub(
                $this->share($receivable['foreign'], $paidAfter, $foreignTotal),
                $this->share($receivable['foreign'], $paidBefore, $foreignTotal),
                2
            );

            if (bccomp($credit, '0', 2) === 0 && bccomp($foreignCredit, '0', 2) === 0) {
                continue;
            }

            $credits[] = new PostingLine($receivable['account'], 'credit', $credit, $invoice->partner_id, $label, $foreignCredit, $invoice->currency, $invoiceRate);
            $closed = bcadd($closed, $credit, 2);
        }

        $lines = [new PostingLine($bank, 'debit', $inflow, $invoice->partner_id, $label, $amount, $invoice->currency, $rate), ...$credits];

        $difference = bcsub($inflow, $closed, 2);

        if (bccomp($difference, '0', 2) > 0) {
            $lines[] = new PostingLine($gain, 'credit', $difference, null, $label);
        } elseif (bccomp($difference, '0', 2) < 0) {
            $lines[] = new PostingLine($loss, 'debit', bcmul($difference, '-1', 2), null, $label);
        }

        return $lines;
    }

    /**
     * Дел од $part што одговара на кумулативно платеното $paid од вкупно $total.
     * Заокружено еднаш на кумулативната сума, па разликата меѓу две состојби е
     * последната рата со стотинката — збирот се затвора точно на $part.
     */
    private function share(string $part, string $paid, string $total): string
    {
        return Bcmath::roundHalfUp(bcdiv(bcmul($part, $paid, 10), $total, 10), 2);
    }

    private function account(SalesInvoice $invoice, string $code): Account
    {
        $account = Account::where('company_id', $invoice->company_id)->analytical()->where('code', $code)->first();

        if ($account === null) {
            throw new InvalidInvoiceStateException("Фирмата нема аналитичко конто {$code}, потребно за девизна наплата.");
        }

        return $account;
    }
}
