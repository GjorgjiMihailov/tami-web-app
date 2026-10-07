<?php

namespace App\Services\Posting;

use App\Models\SalesInvoice;
use App\Support\Bcmath;
use App\Support\Posting\ItemKind;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingSlice;
use App\Support\Posting\VatGroup;

/**
 * Ги пресметува износите на излезна фактура за шемите за книжење.
 *
 * Правилото за заокружување е истото како кај досегашното книжење: бруто се
 * конвертира ЕДНАШ (не збир од посебно заокружени делови), ДДВ се конвертира,
 * основицата е остатокот. Кришките се конвертираат поединечно, а последната ги
 * апсорбира остатоците — па збирот на приходите и ДДВ-то е точно колку
 * побарувањето, за секоја фактура и за секој курс.
 */
final class SalesInvoicePostingContext
{
    public static function build(SalesInvoice $invoice, string $formattedNumber, string $cogsTotal, string $importCogsTotal = '0.00'): PostingContext
    {
        $vatRegistered = (bool) $invoice->company->is_vat_registered;
        $foreign = $invoice->isForeignCurrency();
        $toMkd = fn (string $amount): string => $foreign
            ? Bcmath::roundHalfUp(bcmul($amount, (string) $invoice->exchange_rate, 10), 2)
            : $amount;

        $buckets = [];

        foreach ($invoice->lines as $line) {
            $kind = ($line->item_id !== null && ! $line->item->isService()) ? ItemKind::GOODS : ItemKind::SERVICE;
            $group = VatGroup::forLine((string) $line->vat_treatment, (string) $line->vat_rate);
            $key = $kind->value.'|'.$group->value;

            $buckets[$key] ??= ['kind' => $kind, 'group' => $group, 'net' => '0.00', 'vat' => '0.00'];
            $buckets[$key]['net'] = bcadd($buckets[$key]['net'], $line->lineTotal(), 2);
            $buckets[$key]['vat'] = bcadd($buckets[$key]['vat'], $vatRegistered ? $line->vatAmount() : '0.00', 2);
        }

        ksort($buckets);

        $subtotalForeign = $invoice->subtotal();
        $vatForeign = $vatRegistered ? $invoice->vatTotal() : '0.00';
        $grossForeign = bcadd($subtotalForeign, $vatForeign, 2);

        $gross = $toMkd($grossForeign);
        $vat = $vatRegistered ? $toMkd($vatForeign) : '0.00';
        $net = bcsub($gross, $vat, 2);

        $slices = [];
        foreach ($buckets as $bucket) {
            $slices[] = new PostingSlice(
                $bucket['kind'],
                $bucket['group'],
                $toMkd($bucket['net']),
                $toMkd($bucket['vat']),
                $bucket['net'],
                $bucket['vat'],
            );
        }

        self::absorbRounding($slices, $net, $vat);

        return new PostingContext(
            totals: ['ВКУПНО' => $gross, 'ОСНОВИЦА' => $net, 'ДДВ' => $vat, 'НАБАВНА_ВРЕДНОСТ' => $cogsTotal, 'НАБАВНА_УВОЗ' => $importCogsTotal, 'НАБАВНА_ДОМАШНА' => bcsub($cogsTotal, $importCogsTotal, 2)],
            foreignTotals: ['ВКУПНО' => $grossForeign, 'ОСНОВИЦА' => $subtotalForeign, 'ДДВ' => $vatForeign, 'НАБАВНА_ВРЕДНОСТ' => '0.00', 'НАБАВНА_УВОЗ' => '0.00', 'НАБАВНА_ДОМАШНА' => '0.00'],
            slices: $slices,
            flags: ['has_goods' => bccomp($cogsTotal, '0', 2) > 0, 'cash' => false, 'import' => false],
            partnerId: $invoice->partner_id,
            documentLabel: 'Invoice '.$formattedNumber,
            foreign: $foreign ? ['currency_code' => $invoice->currency, 'exchange_rate' => (string) $invoice->exchange_rate] : null,
        );
    }

    /**
     * Остатокот од заокружувањето оди на последната кришка (основица) и на
     * последната кришка со ДДВ — така збирот по кришки е точно ОСНОВИЦА и ДДВ.
     *
     * @param  list<PostingSlice>  $slices
     */
    private static function absorbRounding(array $slices, string $net, string $vat): void
    {
        if ($slices === []) {
            return;
        }

        $baseSum = array_reduce($slices, fn (string $carry, PostingSlice $s) => bcadd($carry, $s->base, 2), '0.00');
        $last = $slices[array_key_last($slices)];
        $last->base = bcadd($last->base, bcsub($net, $baseSum, 2), 2);

        $vatSum = array_reduce($slices, fn (string $carry, PostingSlice $s) => bcadd($carry, $s->vat, 2), '0.00');
        $vatDiff = bcsub($vat, $vatSum, 2);

        if (bccomp($vatDiff, '0', 2) !== 0) {
            $target = null;
            foreach ($slices as $slice) {
                if (bccomp($slice->vatForeign, '0', 2) !== 0) {
                    $target = $slice;
                }
            }
            $target ??= $last;
            $target->vat = bcadd($target->vat, $vatDiff, 2);
        }
    }
}
