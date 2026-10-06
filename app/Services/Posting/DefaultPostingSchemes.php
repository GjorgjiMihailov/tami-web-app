<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingMatrix;
use Illuminate\Support\Facades\DB;

/**
 * Стандардните шеми. Контата се бараат по код во планот на фирмата и мора да
 * бидат аналитички. Сметководителот ги менува потоа; ова се само почеток.
 */
class DefaultPostingSchemes
{
    /**
     * @return array{name: string, rows: list<array<string, mixed>>, matrix: list<array<string, string|null>>}
     */
    public static function definition(PostingDocType $type): array
    {
        return match ($type) {
            PostingDocType::SALES_INVOICE => self::salesInvoice(),
            PostingDocType::SALES_PAYMENT => self::salesPayment(),
            PostingDocType::PURCHASE_INVOICE => self::purchaseInvoice(),
            PostingDocType::PURCHASE_PAYMENT => self::purchasePayment(),
        };
    }

    public static function create(Company $company, PostingDocType $type): PostingScheme
    {
        $definition = self::definition($type);

        return DB::transaction(function () use ($company, $type, $definition) {
            $scheme = PostingScheme::create(['company_id' => $company->id, 'doc_type' => $type, 'name' => $definition['name']]);
            self::populate($scheme, $company, $definition);

            return $scheme;
        });
    }

    /** Ги создава редовите и матричните сметки на шемата од дефиниција (стандардна). */
    public static function populate(PostingScheme $scheme, Company $company, array $definition): void
    {
        foreach ($definition['rows'] as $position => $row) {
            $scheme->rows()->create([
                'position' => $position + 1,
                'account_mode' => $row['mode'],
                'account_id' => isset($row['account']) ? self::account($company, $row['account'])->id : null,
                'matrix_key' => $row['matrix'] ?? null,
                'side' => $row['side'],
                'formula' => $row['formula'],
                'with_partner' => $row['partner'] ?? false,
                'description' => $row['description'] ?? null,
                'condition' => $row['condition'] ?? null,
            ]);
        }

        foreach ($definition['matrix'] as $entry) {
            $scheme->matrixAccounts()->create([
                'matrix_key' => $entry['key'],
                'item_kind' => $entry['kind'],
                'vat_group' => $entry['group'],
                'account_id' => self::account($company, $entry['account'])->id,
            ]);
        }
    }

    private static function account(Company $company, string $code): Account
    {
        return Account::where('company_id', $company->id)->analytical()->where('code', $code)->firstOrFail();
    }

    private static function salesInvoice(): array
    {
        $revenue = PostingMatrix::REVENUE;
        $vat = PostingMatrix::OUTPUT_VAT;

        return [
            'name' => 'Излезна фактура',
            'rows' => [
                ['mode' => 'fixed', 'account' => '1200', 'side' => 'debit', 'formula' => 'ВКУПНО', 'partner' => true, 'description' => '{фактура}'],
                ['mode' => 'matrix', 'matrix' => $revenue, 'side' => 'credit', 'formula' => 'ОСНОВИЦА', 'partner' => true, 'description' => '{фактура}'],
                ['mode' => 'matrix', 'matrix' => $vat, 'side' => 'credit', 'formula' => 'ДДВ', 'partner' => true, 'description' => 'VAT on {фактура}'],
                ['mode' => 'fixed', 'account' => '7010', 'side' => 'debit', 'formula' => 'НАБАВНА_ВРЕДНОСТ', 'description' => 'COGS for {фактура}', 'condition' => 'has_goods'],
                ['mode' => 'fixed', 'account' => '6600', 'side' => 'credit', 'formula' => 'НАБАВНА_ВРЕДНОСТ', 'description' => 'COGS for {фактура}', 'condition' => 'has_goods'],
            ],
            'matrix' => [
                ['key' => $revenue, 'kind' => 'service', 'group' => 'general', 'account' => '74000'],
                ['key' => $revenue, 'kind' => 'service', 'group' => 'reduced', 'account' => '74001'],
                ['key' => $revenue, 'kind' => 'service', 'group' => 'exempt_with_credit', 'account' => '74002'],
                ['key' => $revenue, 'kind' => 'service', 'group' => 'exempt_without_credit', 'account' => '74003'],
                ['key' => $revenue, 'kind' => 'service', 'group' => 'export', 'account' => '7423'],
                ['key' => $revenue, 'kind' => 'goods', 'group' => 'general', 'account' => '74100'],
                ['key' => $revenue, 'kind' => 'goods', 'group' => 'reduced', 'account' => '74101'],
                ['key' => $revenue, 'kind' => 'goods', 'group' => 'exempt_with_credit', 'account' => '74102'],
                ['key' => $revenue, 'kind' => 'goods', 'group' => 'exempt_without_credit', 'account' => '74103'],
                ['key' => $revenue, 'kind' => 'goods', 'group' => 'export', 'account' => '7421'],
                ['key' => $vat, 'kind' => null, 'group' => 'general', 'account' => '2300'],
                ['key' => $vat, 'kind' => null, 'group' => 'reduced', 'account' => '2301'],
            ],
        ];
    }

    private static function salesPayment(): array
    {
        return [
            'name' => 'Уплата од купувач',
            'rows' => [
                ['mode' => 'fixed', 'account' => '1000', 'side' => 'debit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}', 'condition' => 'not_cash'],
                ['mode' => 'fixed', 'account' => '1020', 'side' => 'debit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}', 'condition' => 'cash'],
                ['mode' => 'invoice', 'side' => 'credit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}'],
            ],
            'matrix' => [],
        ];
    }

    private static function purchaseInvoice(): array
    {
        $vat = PostingMatrix::INPUT_VAT;

        return [
            'name' => 'Влезна фактура',
            'rows' => [
                ['mode' => 'line', 'side' => 'debit', 'formula' => 'ТРОШОК_СТАВКА', 'partner' => true, 'description' => '{фактура}'],
                ['mode' => 'fixed', 'account' => '6600', 'side' => 'debit', 'formula' => 'ЗАЛИХА', 'partner' => true, 'description' => '{фактура}', 'condition' => 'not_import'],
                ['mode' => 'fixed', 'account' => '6601', 'side' => 'debit', 'formula' => 'ЗАЛИХА', 'partner' => true, 'description' => '{фактура}', 'condition' => 'import'],
                ['mode' => 'matrix', 'matrix' => $vat, 'side' => 'debit', 'formula' => 'ДДВ', 'partner' => true, 'description' => 'Input VAT on {фактура}', 'condition' => 'not_import'],
                ['mode' => 'fixed', 'account' => '1302', 'side' => 'debit', 'formula' => 'ОДБИВЛИВ_ДДВ', 'partner' => true, 'description' => 'Input VAT on {фактура}', 'condition' => 'import'],
                ['mode' => 'fixed', 'account' => '2200', 'side' => 'credit', 'formula' => 'ВКУПНО', 'partner' => true, 'description' => '{фактура}', 'condition' => 'not_import'],
                ['mode' => 'fixed', 'account' => '2210', 'side' => 'credit', 'formula' => 'ВКУПНО', 'partner' => true, 'description' => '{фактура}', 'condition' => 'import'],
            ],
            'matrix' => [
                ['key' => $vat, 'kind' => null, 'group' => 'general', 'account' => '1300'],
                ['key' => $vat, 'kind' => null, 'group' => 'reduced', 'account' => '1301'],
            ],
        ];
    }

    private static function purchasePayment(): array
    {
        return [
            'name' => 'Исплата кон добавувач',
            'rows' => [
                ['mode' => 'invoice', 'side' => 'debit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}'],
                ['mode' => 'fixed', 'account' => '1000', 'side' => 'credit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}', 'condition' => 'not_cash'],
                ['mode' => 'fixed', 'account' => '1020', 'side' => 'credit', 'formula' => 'ИЗНОС', 'partner' => true, 'description' => '{фактура}', 'condition' => 'cash'],
            ],
            'matrix' => [],
        ];
    }
}
