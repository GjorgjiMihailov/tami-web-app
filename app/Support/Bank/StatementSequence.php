<?php

namespace App\Support\Bank;

use App\Models\BankStatement;
use Illuminate\Support\Collection;

/**
 * Ги реди изводите и го покажува прекинот во низата.
 *
 * Ова е единствената причина бројот на изводот воопшто да се внесува. Ако
 * постојат 46 и 48, редот меѓу нив кажува дека 47 фали — а фален извод значи
 * непрокнижен промет, што се открива дури на крајот од годината ако никој не
 * гледа.
 *
 * Броењето почнува одново секоја година и е одделно за секоја сметка, па низата
 * се чита по сметка и по година. Изводот 47 на девизната нема врска со изводот
 * 47 на денарската.
 */
class StatementSequence
{
    /**
     * @param  Collection<int, BankStatement>  $statements
     * @return array<int, array{account: string, bank: string, kind: \App\Support\BankStatementKind, year: int, rows: array<int, array<string, mixed>>}>
     */
    public static function groups(Collection $statements): array
    {
        return $statements
            ->groupBy(fn (BankStatement $statement) => $statement->account.'|'.$statement->statement_date->year)
            ->map(function (Collection $group) {
                $ordered = $group->sortBy('number')->values();
                $first = $ordered->first();

                return [
                    'account' => $first->account,
                    'bank' => $first->bank,
                    'kind' => $first->kind,
                    'year' => $first->statement_date->year,
                    'rows' => self::rows($ordered),
                ];
            })
            ->sortBy([
                fn (array $group) => -$group['year'],
                fn (array $group) => $group['account'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, BankStatement>  $ordered
     * @return array<int, array<string, mixed>>
     */
    private static function rows(Collection $ordered): array
    {
        $rows = [];
        $previous = null;

        foreach ($ordered as $statement) {
            if ($previous !== null && $statement->number > $previous->number + 1) {
                $rows[] = [
                    'type' => 'gap',
                    'from' => $previous->number + 1,
                    'to' => $statement->number - 1,
                ];
            }

            $rows[] = ['type' => 'statement', 'statement' => $statement];
            $previous = $statement;
        }

        return $rows;
    }
}
