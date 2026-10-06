<?php

namespace App\Services\Posting;

use App\Exceptions\PostingSchemeException;
use App\Models\PurchaseInvoice;
use App\Support\Posting\ItemKind;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingSlice;
use App\Support\Posting\VatGroup;

/**
 * Ги пресметува износите на влезна фактура за шемите за книжење. Истите
 * правила како досегашното книжење: ставка од залиха оди на залиха, останатите
 * на сметката што ја избрал корисникот, одбивливиот ДДВ посебно, а
 * неодбивливиот е дел од трошокот на ставката.
 */
final class PurchaseInvoicePostingContext
{
    public static function build(PurchaseInvoice $invoice): PostingContext
    {
        $vatRegistered = (bool) $invoice->company->is_vat_registered;

        $stock = '0.00';
        $hasGoods = false;
        $buckets = [];
        $slices = [];
        $deductibleVat = '0.00';

        foreach ($invoice->lines as $line) {
            $net = $line->lineTotal();
            $vat = $vatRegistered ? $line->vatAmount() : '0.00';
            $deductible = $vatRegistered && $line->vat_deductible;
            $cost = $deductible ? $net : bcadd($net, $vat, 2);
            $movesStock = $line->item_id !== null && ! $line->item->isService();

            if ($movesStock) {
                $hasGoods = true;
                $stock = bcadd($stock, $cost, 2);
            } else {
                if ($line->account === null) {
                    throw new PostingSchemeException('Ставка што не е артикл од залиха нема сметка за трошок.');
                }

                $buckets[$line->account_id] ??= ['account' => $line->account, 'amount' => '0.00'];
                $buckets[$line->account_id]['amount'] = bcadd($buckets[$line->account_id]['amount'], $cost, 2);
            }

            if ($deductible && bccomp($vat, '0', 2) !== 0) {
                $kind = $movesStock ? ItemKind::GOODS : ItemKind::SERVICE;
                $group = VatGroup::forLine('standard', (string) $line->vat_rate);
                $key = $kind->value.'|'.$group->value;

                $slices[$key] ??= new PostingSlice($kind, $group, '0.00', '0.00');
                $slices[$key]->base = bcadd($slices[$key]->base, $net, 2);
                $slices[$key]->vat = bcadd($slices[$key]->vat, $vat, 2);
                $deductibleVat = bcadd($deductibleVat, $vat, 2);
            }
        }

        $buckets = array_values($buckets);
        $costTotal = array_reduce($buckets, fn (string $carry, array $b) => bcadd($carry, $b['amount'], 2), '0.00');

        return new PostingContext(
            totals: [
                'ВКУПНО' => bcadd(bcadd($stock, $costTotal, 2), $deductibleVat, 2),
                'ЗАЛИХА' => $stock,
                'ТРОШОК_СТАВКА' => $costTotal,
                'ОДБИВЛИВ_ДДВ' => $deductibleVat,
            ],
            slices: array_values($slices),
            flags: ['has_goods' => $hasGoods, 'cash' => false, 'import' => (bool) $invoice->is_import],
            partnerId: $invoice->partner_id,
            documentLabel: "Purchase bill {$invoice->partner->name} #{$invoice->supplier_invoice_number}",
            accountBuckets: $buckets,
        );
    }
}
