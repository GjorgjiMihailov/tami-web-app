<?php

namespace App\Services\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Support\Posting\ItemKind;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingSlice;
use App\Support\Posting\VatGroup;

/**
 * Готови пробни документи за проверка на шема: типични случаи (стока/услуга,
 * 18%/5%/извоз/ослободено, готово, увоз). Шема се зачувува само ако
 * балансира на сите нив. Износите се измислени, но внатрешно усогласени
 * (вкупно = основица + ДДВ).
 */
final class PostingSampleContexts
{
    /** @return array<string, PostingContext> */
    public static function for(PostingDocType $type, Company $company): array
    {
        return match ($type) {
            PostingDocType::SALES_INVOICE => self::salesInvoice(),
            PostingDocType::PURCHASE_INVOICE => self::purchaseInvoice($company),
            PostingDocType::SALES_PAYMENT => self::payment($company, '1200', 'Payment for invoice 1'),
            PostingDocType::PURCHASE_PAYMENT => self::payment($company, '2200', 'Payment for purchase bill X #1'),
        };
    }

    /** @return array<string, PostingContext> */
    private static function salesInvoice(): array
    {
        $make = fn (array $totals, array $slices, bool $goods) => new PostingContext(
            totals: $totals + ['НАБАВНА_УВОЗ' => '0.00', 'НАБАВНА_ДОМАШНА' => $totals['НАБАВНА_ВРЕДНОСТ']],
            slices: $slices,
            flags: ['has_goods' => $goods, 'cash' => false, 'import' => false],
            partnerId: 1,
            documentLabel: 'Invoice 1',
        );

        return [
            'Услуга со 18% ДДВ' => $make(
                ['ВКУПНО' => '1180.00', 'ОСНОВИЦА' => '1000.00', 'ДДВ' => '180.00', 'НАБАВНА_ВРЕДНОСТ' => '0.00'],
                [new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '1000.00', '180.00')],
                false
            ),
            'Стока со 18% ДДВ и залиха' => $make(
                ['ВКУПНО' => '1180.00', 'ОСНОВИЦА' => '1000.00', 'ДДВ' => '180.00', 'НАБАВНА_ВРЕДНОСТ' => '600.00'],
                [new PostingSlice(ItemKind::GOODS, VatGroup::GENERAL, '1000.00', '180.00')],
                true
            ),
            'Стока од увоз и од дома' => $make(
                ['ВКУПНО' => '1180.00', 'ОСНОВИЦА' => '1000.00', 'ДДВ' => '180.00', 'НАБАВНА_ВРЕДНОСТ' => '600.00', 'НАБАВНА_УВОЗ' => '400.00', 'НАБАВНА_ДОМАШНА' => '200.00'],
                [new PostingSlice(ItemKind::GOODS, VatGroup::GENERAL, '1000.00', '180.00')],
                true
            ),
            'Мешана фактура (5%, 18%, извоз)' => $make(
                ['ВКУПНО' => '614.00', 'ОСНОВИЦА' => '550.00', 'ДДВ' => '64.00', 'НАБАВНА_ВРЕДНОСТ' => '120.00'],
                [
                    new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '200.00', '10.00'),
                    new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '300.00', '54.00'),
                    new PostingSlice(ItemKind::SERVICE, VatGroup::EXPORT, '50.00', '0.00'),
                ],
                true
            ),
            'Ослободено од ДДВ' => $make(
                ['ВКУПНО' => '100.00', 'ОСНОВИЦА' => '100.00', 'ДДВ' => '0.00', 'НАБАВНА_ВРЕДНОСТ' => '0.00'],
                [
                    new PostingSlice(ItemKind::GOODS, VatGroup::EXEMPT_WITH_CREDIT, '70.00', '0.00'),
                    new PostingSlice(ItemKind::SERVICE, VatGroup::EXEMPT_WITHOUT_CREDIT, '30.00', '0.00'),
                ],
                false
            ),
        ];
    }

    /** @return array<string, PostingContext> */
    private static function purchaseInvoice(Company $company): array
    {
        $expense = self::anyAccount($company, 'expense');

        $make = fn (array $totals, array $slices, array $buckets, bool $goods, bool $import) => new PostingContext(
            totals: $totals,
            slices: $slices,
            flags: ['has_goods' => $goods, 'cash' => false, 'import' => $import],
            partnerId: 1,
            documentLabel: 'Purchase bill X #1',
            accountBuckets: array_map(fn (string $amount) => ['account' => $expense, 'amount' => $amount], $buckets),
        );

        return [
            'Трошок со 18% ДДВ' => $make(
                ['ВКУПНО' => '1180.00', 'ЗАЛИХА' => '0.00', 'ТРОШОК_СТАВКА' => '1000.00', 'ОДБИВЛИВ_ДДВ' => '180.00'],
                [new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '1000.00', '180.00')],
                ['1000.00'], false, false
            ),
            'Домашна стока со 5% ДДВ' => $make(
                ['ВКУПНО' => '525.00', 'ЗАЛИХА' => '500.00', 'ТРОШОК_СТАВКА' => '0.00', 'ОДБИВЛИВ_ДДВ' => '25.00'],
                [new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '500.00', '25.00')],
                [], true, false
            ),
            'Увозна стока и транспорт' => $make(
                ['ВКУПНО' => '618.00', 'ЗАЛИХА' => '500.00', 'ТРОШОК_СТАВКА' => '100.00', 'ОДБИВЛИВ_ДДВ' => '18.00'],
                [new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '100.00', '18.00')],
                ['100.00'], true, true
            ),
            'Неодбивлив ДДВ' => $make(
                ['ВКУПНО' => '1180.00', 'ЗАЛИХА' => '0.00', 'ТРОШОК_СТАВКА' => '1180.00', 'ОДБИВЛИВ_ДДВ' => '0.00'],
                [],
                ['1180.00'], false, false
            ),
        ];
    }

    /** @return array<string, PostingContext> */
    private static function payment(Company $company, string $code, string $label): array
    {
        $account = self::anyAccount($company, $code);

        $make = fn (bool $cash) => new PostingContext(
            totals: ['ИЗНОС' => '100.00'],
            flags: ['has_goods' => false, 'cash' => $cash, 'import' => false],
            partnerId: 1,
            documentLabel: $label,
            invoiceAccount: $account,
        );

        return ['Плаќање преку банка' => $make(false), 'Плаќање во готово' => $make(true)];
    }

    /** Вистинско аналитичко конто на фирмата: по шифра, или (трошок) првото на 4, или кое било. */
    private static function anyAccount(Company $company, string $codeOrExpense): Account
    {
        $base = Account::where('company_id', $company->id)->analytical()->where('is_active', true)->orderBy('code');

        $found = $codeOrExpense === 'expense'
            ? (clone $base)->where('code', 'like', '4%')->first()
            : (clone $base)->where('code', $codeOrExpense)->first();

        return $found ?? $base->firstOrFail();
    }
}
