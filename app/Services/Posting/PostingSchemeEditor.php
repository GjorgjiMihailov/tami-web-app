<?php

namespace App\Services\Posting;

use App\Exceptions\PostingFormulaException;
use App\Exceptions\PostingSchemeException;
use App\Models\Account;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\PostingSchemeMatrixAccount;
use App\Models\PostingSchemeRow;
use App\Support\Posting\FormulaEvaluator;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingVocabulary;
use Illuminate\Support\Facades\DB;

/**
 * Менување на шема: работна копија (низи) → проверка → запис одеднаш. Шемата
 * се запишува само ако е исправна и балансира на сите пробни документи;
 * налозите што веќе се книжени не се допираат.
 */
class PostingSchemeEditor
{
    /** @return array{rows: list<array<string, mixed>>, matrix: list<array<string, mixed>>} */
    public function draftOf(PostingScheme $scheme): array
    {
        $scheme->load(['rows.account', 'matrixAccounts.account']);

        return [
            'rows' => $scheme->rows->map(fn (PostingSchemeRow $row) => [
                'account_mode' => $row->account_mode,
                'account_code' => $row->account?->code,
                'matrix_key' => $row->matrix_key,
                'side' => $row->side,
                'formula' => $row->formula,
                'with_partner' => (bool) $row->with_partner,
                'description' => $row->description,
                'condition' => $row->condition,
            ])->values()->all(),
            'matrix' => $scheme->matrixAccounts->map(fn (PostingSchemeMatrixAccount $m) => [
                'matrix_key' => $m->matrix_key,
                'item_kind' => $m->item_kind,
                'vat_group' => $m->vat_group,
                'account_code' => $m->account->code,
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $matrix
     * @return list<string>
     */
    public function validate(Company $company, PostingDocType $type, string $name, array $rows, array $matrix): array
    {
        $errors = [];

        if ($rows === []) {
            return ['Шемата нема ниту еден ред.'];
        }

        $accounts = $this->accountsByCode($company, $rows, $matrix);
        $modes = PostingVocabulary::modes($type);
        $conditions = PostingVocabulary::conditions($type);
        $matrices = PostingVocabulary::matrices($type);

        foreach ($rows as $index => $row) {
            $n = $index + 1;
            $mode = (string) ($row['account_mode'] ?? '');

            if (! array_key_exists($mode, $modes)) {
                $errors[] = "Ред {$n}: начинот на конто „{$mode}“ не е дозволен за „{$type->label()}“.";

                continue;
            }

            if (! in_array($row['side'] ?? null, ['debit', 'credit'], true)) {
                $errors[] = "Ред {$n}: страната мора да биде Должи или Побарува.";
            }

            $condition = (string) ($row['condition'] ?? '');
            if ($condition !== '' && ! array_key_exists($condition, $conditions)) {
                $errors[] = "Ред {$n}: условот „{$condition}“ не е дозволен за „{$type->label()}“.";
            }

            if ($mode === 'fixed') {
                $errors = array_merge($errors, $this->accountErrors($accounts, (string) ($row['account_code'] ?? ''), "Ред {$n}"));
            }

            if ($mode === 'matrix') {
                $key = (string) ($row['matrix_key'] ?? '');
                if (! array_key_exists($key, $matrices)) {
                    $errors[] = "Ред {$n}: матрицата „{$key}“ не постои за „{$type->label()}“.";
                }
            }

            $errors = array_merge($errors, $this->formulaErrors($type, $mode, (string) ($row['formula'] ?? ''), $n));
        }

        $allowedCells = [];
        foreach (array_keys($matrices) as $key) {
            foreach (PostingVocabulary::matrixCells($key) as $cell) {
                $allowedCells[$cell['matrix_key'].'|'.($cell['item_kind'] ?? '').'|'.$cell['vat_group']] = true;
            }
        }

        $seen = [];
        foreach ($matrix as $entry) {
            $cell = $entry['matrix_key'].'|'.($entry['item_kind'] ?? '').'|'.$entry['vat_group'];
            $where = "Матрица „{$entry['matrix_key']}“ ({$entry['vat_group']})";

            if (! isset($allowedCells[$cell])) {
                $errors[] = "{$where}: ќелијата не е дозволена за „{$type->label()}“.";
            } elseif (isset($seen[$cell])) {
                $errors[] = "{$where}: ќелијата е внесена двапати.";
            } else {
                $errors = array_merge($errors, $this->accountErrors($accounts, (string) $entry['account_code'], $where));
            }

            $seen[$cell] = true;
        }

        if ($errors !== []) {
            return $errors;
        }

        $scheme = $this->transientScheme($company, $type, $name, $rows, $matrix);
        $engine = new PostingSchemeEngine;

        foreach (PostingSampleContexts::for($type, $company) as $sample => $context) {
            try {
                $engine->lines($scheme, $context);
            } catch (PostingSchemeException|PostingFormulaException $e) {
                $errors[] = "Пробен документ „{$sample}“: ".$e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $matrix
     * @return list<string>
     */
    public function save(PostingScheme $scheme, array $rows, array $matrix): array
    {
        $company = $scheme->company;
        $errors = $this->validate($company, $scheme->doc_type, $scheme->name, $rows, $matrix);

        if ($errors !== []) {
            return $errors;
        }

        $accounts = $this->accountsByCode($company, $rows, $matrix);

        DB::transaction(function () use ($scheme, $rows, $matrix, $accounts) {
            $scheme->rows()->delete();
            $scheme->matrixAccounts()->delete();

            foreach ($rows as $index => $row) {
                $scheme->rows()->create([
                    'position' => $index + 1,
                    'account_mode' => $row['account_mode'],
                    'account_id' => $row['account_mode'] === 'fixed' ? $accounts[$row['account_code']]->id : null,
                    'matrix_key' => $row['account_mode'] === 'matrix' ? $row['matrix_key'] : null,
                    'side' => $row['side'],
                    'formula' => $row['formula'],
                    'with_partner' => (bool) ($row['with_partner'] ?? false),
                    'description' => filled($row['description'] ?? null) ? $row['description'] : null,
                    'condition' => filled($row['condition'] ?? null) ? $row['condition'] : null,
                ]);
            }

            foreach ($matrix as $entry) {
                $scheme->matrixAccounts()->create([
                    'matrix_key' => $entry['matrix_key'],
                    'item_kind' => $entry['item_kind'] ?? null,
                    'vat_group' => $entry['vat_group'],
                    'account_id' => $accounts[$entry['account_code']]->id,
                ]);
            }
        });

        return [];
    }

    public function resetToDefault(PostingScheme $scheme): void
    {
        $definition = DefaultPostingSchemes::definition($scheme->doc_type);

        DB::transaction(function () use ($scheme, $definition) {
            $scheme->rows()->delete();
            $scheme->matrixAccounts()->delete();
            DefaultPostingSchemes::populate($scheme, $scheme->company, $definition);
        });
    }

    /**
     * Незапишана шема од работна копија — за проверка и пробно книжење.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $matrix
     */
    public function transientScheme(Company $company, PostingDocType $type, string $name, array $rows, array $matrix): PostingScheme
    {
        $accounts = $this->accountsByCode($company, $rows, $matrix);

        $scheme = new PostingScheme(['company_id' => $company->id, 'doc_type' => $type, 'name' => $name]);

        $scheme->setRelation('rows', collect($rows)->map(function (array $row, int $index) use ($accounts) {
            $model = new PostingSchemeRow([
                'position' => $index + 1,
                'account_mode' => $row['account_mode'] ?? '',
                'matrix_key' => $row['matrix_key'] ?? null,
                'side' => $row['side'] ?? '',
                'formula' => $row['formula'] ?? '',
                'with_partner' => (bool) ($row['with_partner'] ?? false),
                'description' => $row['description'] ?? null,
                'condition' => filled($row['condition'] ?? null) ? $row['condition'] : null,
            ]);
            $model->setRelation('account', $accounts[(string) ($row['account_code'] ?? '')] ?? null);

            return $model;
        })->values());

        $scheme->setRelation('matrixAccounts', collect($matrix)->map(function (array $entry) use ($accounts) {
            $model = new PostingSchemeMatrixAccount([
                'matrix_key' => $entry['matrix_key'],
                'item_kind' => $entry['item_kind'] ?? null,
                'vat_group' => $entry['vat_group'],
            ]);
            $model->setRelation('account', $accounts[(string) $entry['account_code']] ?? null);

            return $model;
        })->values());

        return $scheme;
    }

    /** @return array<string, Account> */
    private function accountsByCode(Company $company, array $rows, array $matrix): array
    {
        $codes = array_values(array_unique(array_filter(array_merge(
            array_map(fn ($r) => (string) ($r['account_code'] ?? ''), $rows),
            array_map(fn ($m) => (string) ($m['account_code'] ?? ''), $matrix),
        ))));

        return Account::where('company_id', $company->id)->whereIn('code', $codes)->get()->keyBy('code')->all();
    }

    /** @return list<string> */
    private function accountErrors(array $accounts, string $code, string $where): array
    {
        if ($code === '') {
            return ["{$where}: не е избрано конто."];
        }

        $account = $accounts[$code] ?? null;

        if ($account === null) {
            return ["{$where}: контото {$code} не постои во планот на фирмата."];
        }

        if (! $account->is_analytical) {
            return ["{$where}: контото {$code} не е аналитичко — книжењето оди само на аналитички конта."];
        }

        if (! $account->is_active) {
            return ["{$where}: контото {$code} не е активно."];
        }

        return [];
    }

    /** @return list<string> */
    private function formulaErrors(PostingDocType $type, string $mode, string $formula, int $n): array
    {
        if (trim($formula) === '') {
            return ["Ред {$n}: формулата е празна."];
        }

        $allowed = PostingVocabulary::allowedVariables($type, $mode);

        try {
            $unknown = array_diff(FormulaEvaluator::variablesIn($formula), $allowed);

            if ($unknown !== []) {
                return ["Ред {$n}: променливата „".implode('“, „', $unknown).'“ не постои за овој документ.'];
            }

            FormulaEvaluator::evaluate($formula, array_fill_keys($allowed, '1.00'));
        } catch (PostingFormulaException $e) {
            return ["Ред {$n}: ".$e->getMessage()];
        }

        return [];
    }
}
