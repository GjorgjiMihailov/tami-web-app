<?php

namespace App\Support\Posting;

/**
 * Што смее да стои во шема за кој вид документ: променливи, услови, начини на
 * конто и матрици. Екранот ги прикажува како помош, а проверката на шема ги
 * користи како дозволена листа — формулата не може да се повика на променлива
 * што документот не ја дава.
 */
final class PostingVocabulary
{
    /** @return array<string, string> */
    public static function variables(PostingDocType $type): array
    {
        return match ($type) {
            PostingDocType::SALES_INVOICE => [
                'ВКУПНО' => 'Вкупно со ДДВ, во денари',
                'ОСНОВИЦА' => 'Вкупно без ДДВ (приход)',
                'ДДВ' => 'Вкупен ДДВ',
                'НАБАВНА_ВРЕДНОСТ' => 'Набавна вредност на продадената стока (од залиха)',
            ],
            PostingDocType::PURCHASE_INVOICE => [
                'ВКУПНО' => 'Вкупно за плаќање (обврската кон добавувачот)',
                'ЗАЛИХА' => 'Вредност на ставките со артикл од залиха',
                'ТРОШОК_СТАВКА' => 'Трошок на ставките што не се залиха (по сметка од ставката)',
                'ОДБИВЛИВ_ДДВ' => 'Одбивлив влезен ДДВ',
            ],
            PostingDocType::SALES_PAYMENT, PostingDocType::PURCHASE_PAYMENT => [
                'ИЗНОС' => 'Износ на уплатата/исплатата, во денари',
            ],
        };
    }

    /** @return array<string, string> */
    public static function matrixVariables(): array
    {
        return [
            'ОСНОВИЦА' => 'Основица на кришката (вид × даночна група)',
            'ДДВ' => 'ДДВ на кришката',
            'ВКУПНО' => 'Основица + ДДВ на кришката',
        ];
    }

    public static function lineVariable(): string
    {
        return 'ТРОШОК_СТАВКА';
    }

    /** @return list<string> */
    public static function allowedVariables(PostingDocType $type, string $mode): array
    {
        $names = array_keys(self::variables($type));

        if ($mode === 'matrix') {
            $names = array_merge($names, array_keys(self::matrixVariables()));
        }

        if ($mode === 'line') {
            $names[] = self::lineVariable();
        }

        return array_values(array_unique($names));
    }

    /** @return array<string, string> */
    public static function modes(PostingDocType $type): array
    {
        return match ($type) {
            PostingDocType::SALES_INVOICE => ['fixed' => 'Точно конто', 'matrix' => 'Конто од матрица'],
            PostingDocType::PURCHASE_INVOICE => ['fixed' => 'Точно конто', 'matrix' => 'Конто од матрица', 'line' => 'Сметка од ставката'],
            PostingDocType::SALES_PAYMENT, PostingDocType::PURCHASE_PAYMENT => ['fixed' => 'Точно конто', 'invoice' => 'Сметка на фактурата'],
        };
    }

    /** @return array<string, string> */
    public static function conditions(PostingDocType $type): array
    {
        $pairs = match ($type) {
            PostingDocType::SALES_INVOICE => ['has_goods' => 'има стока од залиха'],
            PostingDocType::PURCHASE_INVOICE => ['has_goods' => 'има стока од залиха', 'import' => 'увозна фактура'],
            PostingDocType::SALES_PAYMENT, PostingDocType::PURCHASE_PAYMENT => ['cash' => 'готовинска уплата/исплата'],
        };

        $conditions = [];

        foreach ($pairs as $key => $label) {
            $conditions[$key] = 'Само ако: '.$label;
            $conditions['not_'.$key] = 'Само ако НЕ: '.$label;
        }

        return $conditions;
    }

    /** @return array<string, string> */
    public static function matrices(PostingDocType $type): array
    {
        return match ($type) {
            PostingDocType::SALES_INVOICE => [
                PostingMatrix::REVENUE => 'Приход (вид × даночна група)',
                PostingMatrix::OUTPUT_VAT => 'Излезен ДДВ (даночна група)',
            ],
            PostingDocType::PURCHASE_INVOICE => [PostingMatrix::INPUT_VAT => 'Влезен ДДВ (даночна група)'],
            default => [],
        };
    }

    /** @return list<array{matrix_key: string, item_kind: ?string, vat_group: string, label: string}> */
    public static function matrixCells(string $matrixKey): array
    {
        $cells = [];

        if (PostingMatrix::byKind($matrixKey)) {
            foreach (ItemKind::cases() as $kind) {
                foreach (VatGroup::cases() as $group) {
                    $cells[] = ['matrix_key' => $matrixKey, 'item_kind' => $kind->value, 'vat_group' => $group->value, 'label' => $kind->label().' — '.$group->label()];
                }
            }

            return $cells;
        }

        foreach ([VatGroup::GENERAL, VatGroup::REDUCED] as $group) {
            $cells[] = ['matrix_key' => $matrixKey, 'item_kind' => null, 'vat_group' => $group->value, 'label' => $group->label()];
        }

        return $cells;
    }
}
