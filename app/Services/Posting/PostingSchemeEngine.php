<?php

namespace App\Services\Posting;

use App\Exceptions\PostingSchemeException;
use App\Models\Account;
use App\Models\PostingScheme;
use App\Models\PostingSchemeRow;
use App\Support\Posting\FormulaEvaluator;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingLine;
use App\Support\Posting\PostingMatrix;
use App\Support\Posting\PostingSlice;

/**
 * Од шема и контекст на документ ги прави ставките на книжењето. Чист: ништо не
 * запишува. Гарантира: само аналитички конта, нула-износи се прескокнуваат, и
 * збирот Должи = збирот Побарува.
 */
class PostingSchemeEngine
{
    private const KNOWN_FLAGS = ['has_goods', 'cash', 'import'];

    /** @return list<PostingLine> */
    public function lines(PostingScheme $scheme, PostingContext $context): array
    {
        $scheme->loadMissing(['rows.account', 'matrixAccounts.account']);
        $lines = [];

        foreach ($scheme->rows as $row) {
            if (! $this->conditionHolds($row->condition, $context)) {
                continue;
            }

            foreach ($this->expand($scheme, $row, $context) as [$account, $variables, $foreignVariables]) {
                $line = $this->line($row, $account, $variables, $foreignVariables, $context);

                if ($line !== null) {
                    $lines[] = $line;
                }
            }
        }

        $this->assertBalanced($lines, $scheme);

        return $lines;
    }

    private function conditionHolds(?string $condition, PostingContext $context): bool
    {
        if ($condition === null || $condition === '') {
            return true;
        }

        $negated = str_starts_with($condition, 'not_');
        $flag = $negated ? substr($condition, 4) : $condition;

        if (! in_array($flag, self::KNOWN_FLAGS, true)) {
            throw new PostingSchemeException("Непознат услов „{$condition}“ во шемата.");
        }

        $value = (bool) ($context->flags[$flag] ?? false);

        return $negated ? ! $value : $value;
    }

    /** @return list<array{0: Account, 1: array<string, string>, 2: array<string, string>}> */
    private function expand(PostingScheme $scheme, PostingSchemeRow $row, PostingContext $context): array
    {
        if ($row->account_mode === 'fixed') {
            return [[$this->checked($row->account, $row), $context->totals, $context->foreignTotals]];
        }

        if ($row->account_mode === 'invoice') {
            if ($context->invoiceAccount === null) {
                throw new PostingSchemeException('Редот бара сметка од документот, а таа не е позната.');
            }

            // Сметката е веќе употребена при книжењето на самата фактура и не се
            // проверува за аналитичност: стара фактура книжена на 120 (наслов)
            // пред шемите мора да се затвори на 120, не на друго конто.
            return [[$context->invoiceAccount, $context->totals, $context->foreignTotals]];
        }

        if ($row->account_mode === 'line') {
            // Сметката ја избрал корисникот на ставката на документот, но и таа
            // мора да е аналитичка — книжењето оди само на аналитички конта.
            $expanded = [];

            foreach ($context->accountBuckets as $bucket) {
                $expanded[] = [$this->checked($bucket['account'], $row), $context->totals + ['ТРОШОК_СТАВКА' => $bucket['amount']], $context->foreignTotals];
            }

            return $expanded;
        }

        if ($row->account_mode !== 'matrix' || $row->matrix_key === null) {
            throw new PostingSchemeException("Непознат начин на конто „{$row->account_mode}“.");
        }

        $expanded = [];

        foreach ($this->groupedSlices($row->matrix_key, $context->slices) as $slice) {
            if (bccomp($slice->base, '0', 2) === 0 && bccomp($slice->vat, '0', 2) === 0) {
                continue;
            }

            $variables = ['ОСНОВИЦА' => $slice->base, 'ДДВ' => $slice->vat, 'ВКУПНО' => bcadd($slice->base, $slice->vat, 2)];

            // Нула не бара конто: ДДВ на извоз или ослободена кришка е 0 и во
            // ДДВ-матрицата нема (и не треба да има) конто за таа група.
            if (bccomp(FormulaEvaluator::evaluate($row->formula, $variables), '0', 2) === 0) {
                continue;
            }

            $expanded[] = [
                $this->checked($this->matrixAccount($scheme, $row->matrix_key, $slice), $row),
                $variables,
                ['ОСНОВИЦА' => $slice->baseForeign, 'ДДВ' => $slice->vatForeign, 'ВКУПНО' => bcadd($slice->baseForeign, $slice->vatForeign, 2)],
            ];
        }

        return $expanded;
    }

    /**
     * Матрицата по вид×група ги чува кришките како што се; матрицата само по
     * група ги собира сите видови на иста група во една.
     *
     * @param  list<PostingSlice>  $slices
     * @return list<PostingSlice>
     */
    private function groupedSlices(string $matrixKey, array $slices): array
    {
        if (PostingMatrix::byKind($matrixKey)) {
            return $slices;
        }

        $merged = [];

        foreach ($slices as $slice) {
            $key = $slice->vatGroup->value;

            if (! isset($merged[$key])) {
                $merged[$key] = new PostingSlice($slice->itemKind, $slice->vatGroup, '0.00', '0.00', '0.00', '0.00');
            }

            $merged[$key]->base = bcadd($merged[$key]->base, $slice->base, 2);
            $merged[$key]->vat = bcadd($merged[$key]->vat, $slice->vat, 2);
            $merged[$key]->baseForeign = bcadd($merged[$key]->baseForeign, $slice->baseForeign, 2);
            $merged[$key]->vatForeign = bcadd($merged[$key]->vatForeign, $slice->vatForeign, 2);
        }

        return array_values($merged);
    }

    private function matrixAccount(PostingScheme $scheme, string $matrixKey, PostingSlice $slice): Account
    {
        $byKind = PostingMatrix::byKind($matrixKey);

        $candidates = $scheme->matrixAccounts->filter(
            fn ($m) => $m->matrix_key === $matrixKey
                && $m->vat_group === $slice->vatGroup->value
                && ($byKind ? $m->item_kind === $slice->itemKind->value : $m->item_kind === null)
        );

        $match = $candidates->first() ?? ($byKind
            ? $scheme->matrixAccounts->first(fn ($m) => $m->matrix_key === $matrixKey && $m->vat_group === $slice->vatGroup->value && $m->item_kind === null)
            : null);

        if ($match === null) {
            $kind = $byKind ? $slice->itemKind->label().', ' : '';

            throw new PostingSchemeException("Во матрицата „{$matrixKey}“ нема конто за {$kind}{$slice->vatGroup->label()}.");
        }

        return $match->account;
    }

    private function checked(?Account $account, PostingSchemeRow $row): Account
    {
        if ($account === null) {
            throw new PostingSchemeException('Редот на шемата нема конто (контото е избришано или не е избрано).');
        }

        if (! $account->is_analytical) {
            throw new PostingSchemeException("Контото {$account->code} не е аналитичко — книжењето оди само на аналитички конта.");
        }

        return $account;
    }

    /**
     * @param  array<string, string>  $variables
     * @param  array<string, string>  $foreignVariables
     */
    private function line(PostingSchemeRow $row, Account $account, array $variables, array $foreignVariables, PostingContext $context): ?PostingLine
    {
        $amount = FormulaEvaluator::evaluate($row->formula, $variables);

        if (bccomp($amount, '0', 2) === 0) {
            return null;
        }

        $side = $row->side;
        $negative = bccomp($amount, '0', 2) < 0;

        if ($negative) {
            $amount = bcmul($amount, '-1', 2);
            $side = $side === 'debit' ? 'credit' : 'debit';
        }

        $foreignAmount = null;
        $currencyCode = null;
        $exchangeRate = null;

        if ($row->with_partner && $context->foreign !== null) {
            $foreignAmount = FormulaEvaluator::evaluate($row->formula, $foreignVariables);
            $foreignAmount = $negative ? bcmul($foreignAmount, '-1', 2) : $foreignAmount;
            $currencyCode = $context->foreign['currency_code'];
            $exchangeRate = $context->foreign['exchange_rate'];
        }

        return new PostingLine(
            account: $account,
            side: $side,
            amount: $amount,
            partnerId: $row->with_partner ? $context->partnerId : null,
            description: str_replace('{фактура}', $context->documentLabel, (string) $row->description),
            foreignAmount: $foreignAmount,
            currencyCode: $currencyCode,
            exchangeRate: $exchangeRate,
        );
    }

    /** @param list<PostingLine> $lines */
    private function assertBalanced(array $lines, PostingScheme $scheme): void
    {
        $debit = '0.00';
        $credit = '0.00';

        foreach ($lines as $line) {
            if ($line->side === 'debit') {
                $debit = bcadd($debit, $line->amount, 2);
            } else {
                $credit = bcadd($credit, $line->amount, 2);
            }
        }

        if (bccomp($debit, $credit, 2) !== 0) {
            throw new PostingSchemeException("Шемата „{$scheme->name}“ не се балансира (должи {$debit}, побарува {$credit}).");
        }
    }
}
