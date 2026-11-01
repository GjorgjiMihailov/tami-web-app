<?php

namespace App\Services\Bank;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalEntryLine;
use App\Support\Bank\LineDirection;
use App\Support\Bcmath;

/**
 * Курсната разлика на 1039 кога денарскиот извод ја затвора девизната продажба.
 *
 * Редот од изводот на конто 1039 носи девизен износ и валута. Кога по тој ред
 * девизното салдо на 1039 за таа валута е нула, а денарското не е, остатокот не
 * е нова конверзија туку разлика меѓу курсот на продажбата и она што банката
 * го исплатила: системот ја предлага (загуба на 4750, добивка на 7750).
 * Предлогот се книжи само кога сметководителот ќе го прифати (`settle_difference`).
 */
final class Settlement1039
{
    public const CODE = '1039';

    /**
     * @return array<int, array{side: 'loss'|'gain', amount: string}> клуч = id на ставката на изводот
     */
    public static function proposals(BankStatement $statement): array
    {
        if ($statement->isBooked() || $statement->kind->isForeign()) {
            return [];
        }

        $statement->loadMissing('lines.account');

        if ($statement->lines->filter(fn (BankStatementLine $line) => self::isSettling($line))->isEmpty()) {
            return [];
        }

        [$mkd, $foreign] = self::balances($statement->company_id);
        $proposals = [];

        foreach ($statement->lines as $line) {
            if (! self::isSettling($line)) {
                continue;
            }

            $currency = (string) $line->currency;
            $mkd[$currency] = bcsub($mkd[$currency] ?? '0.00', (string) $line->amount, 2);
            $foreign[$currency] = bcsub($foreign[$currency] ?? '0.00', (string) $line->foreign_amount, 2);

            if (bccomp($foreign[$currency], '0', 2) === 0 && bccomp($mkd[$currency], '0', 2) !== 0) {
                $left = $mkd[$currency];
                $proposals[$line->id] = [
                    'side' => bccomp($left, '0', 2) > 0 ? 'loss' : 'gain',
                    'amount' => ltrim($left, '-'),
                ];
                $mkd[$currency] = '0.00';
            }
        }

        return $proposals;
    }

    /** Ставка на изводот што е на 1039 и носи девизен износ. */
    public static function carriesForeign(BankStatementLine $line): bool
    {
        return $line->account?->code === self::CODE
            && $line->foreign_amount !== null
            && bccomp((string) $line->foreign_amount, '0', 2) > 0
            && $line->currency !== null && $line->currency !== '';
    }

    /**
     * Девизните колони на ставката од налогот. Курсот е денари ÷ девиза.
     *
     * @return array<string, string>
     */
    public static function foreignColumns(BankStatementLine $line): array
    {
        if (! self::carriesForeign($line)) {
            return [];
        }

        return [
            'currency_code' => (string) $line->currency,
            'foreign_amount' => (string) $line->foreign_amount,
            'exchange_rate' => Bcmath::roundHalfUp(bcdiv((string) $line->amount, (string) $line->foreign_amount, 10), 6),
        ];
    }

    private static function isSettling(BankStatementLine $line): bool
    {
        return $line->direction === LineDirection::IN && self::carriesForeign($line);
    }

    /**
     * Салдо на 1039 од книжените налози на фирмата, по валута: денари и девиза.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private static function balances(int $companyId): array
    {
        $mkd = [];
        $foreign = [];

        $rows = JournalEntryLine::whereHas('account', fn ($query) => $query->where('company_id', $companyId)->where('code', self::CODE))
            ->whereNotNull('currency_code')
            ->get(['debit', 'credit', 'currency_code', 'foreign_amount']);

        foreach ($rows as $row) {
            $currency = (string) $row->currency_code;
            $mkd[$currency] = bcadd($mkd[$currency] ?? '0.00', bcsub((string) $row->debit, (string) $row->credit, 2), 2);

            if ($row->foreign_amount !== null) {
                $signed = bccomp((string) $row->debit, '0', 2) > 0 ? (string) $row->foreign_amount : bcmul((string) $row->foreign_amount, '-1', 2);
                $foreign[$currency] = bcadd($foreign[$currency] ?? '0.00', $signed, 2);
            }
        }

        return [$mkd, $foreign];
    }
}
