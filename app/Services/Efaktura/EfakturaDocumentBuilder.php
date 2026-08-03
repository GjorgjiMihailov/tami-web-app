<?php

namespace App\Services\Efaktura;

use App\Models\SalesInvoice;
use App\Support\Bcmath;

class EfakturaDocumentBuilder
{
    public function build(SalesInvoice $invoice): array
    {
        $company = $invoice->company;
        $partner = $invoice->partner;
        $docNumber = "{$invoice->fiscal_year}-{$invoice->invoice_number}";
        $today = $invoice->invoice_date->toDateString();

        $items = $invoice->lines->values()->map(fn ($line, $index) => $this->buildItem($line, $index + 1))->all();

        $netAmount = (float) Bcmath::roundHalfUp(
            $invoice->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->lineTotal(), 10), '0'),
            2
        );
        $vatAmount = (float) Bcmath::roundHalfUp(
            $invoice->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->vatAmount(), 10), '0'),
            2
        );
        $grossAmount = round($netAmount + $vatAmount, 2);

        return [
            // UJP requires Skopje local time with no "Z" suffix (confirmed in
            // efakturawiki.ujp.gov.mk's 27.05.2026 changelog entry and every worked
            // example in primer_za_json_3.pdf) — the app's default timezone is UTC
            // (config/app.php), so this must convert explicitly, not just append "Z".
            'requestTimestamp' => now()->timezone('Europe/Skopje')->format('Y-m-d\TH:i:s'),
            'document' => [
                'header' => [
                    'docStorno' => 0,
                    'docType' => '100',
                    'docTypeName' => 'Фактура',
                    'docDate' => $today,
                    'docTurnoverDate' => $today,
                    'docNumber' => $docNumber,
                    'docId' => $docNumber,
                    'docNotes' => $invoice->notes,
                    'docHeader' => null,
                    'docFooter' => null,
                ],
                'docReferences' => [],
                'seller' => $this->buildParty($company->name, $company->tax_id, $company->street_address, $company->street_number, $company->postal_code, $company->city, $company->is_vat_registered, 'seller'),
                'buyer' => $this->buildParty($partner->name, $partner->tax_id, $partner->street_address, $partner->street_number, $partner->postal_code, $partner->city, $partner->is_vat_registered, 'buyer'),
                'docPayment' => [
                    'docPaymentTypeCode' => $invoice->payment_type_code,
                    'docPaymentTypeDesc' => SalesInvoice::PAYMENT_TYPES[$invoice->payment_type_code] ?? $invoice->payment_type_code,
                    'docPaymentTypeDueDays' => null,
                    'docPaymentTypeDueDate' => null,
                    'docPaymentTerms' => null,
                    'docPaymentNote' => null,
                    'docPaymentInterest' => null,
                    'docPaymentDiscount' => null,
                    'docCurrency' => 'MKD',
                    'docCurrencyCode' => 'MKD',
                    'docCurrencyDate' => $today,
                    'docCurrencyExchRate' => 1,
                ],
                'docItems' => $items,
                'docTotals' => [
                    'docNetAmount' => $netAmount,
                    'docDiscountAmount' => 0,
                    'docNetAmountDisc' => $netAmount,
                    'docVatAmount' => $vatAmount,
                    'docGrossAmount' => $grossAmount,
                    'docGrossAmountR' => $grossAmount,
                    'docAvansAmount' => 0,
                    'docFinalAmount' => $grossAmount,
                ],
                'vatTotals' => $this->buildVatTotals($invoice),
            ],
        ];
    }

    private function buildParty(?string $name, ?string $taxId, ?string $streetAddress, ?string $streetNumber, ?string $postalCode, ?string $city, bool $isVatRegistered, string $prefix): array
    {
        return [
            "{$prefix}CCode" => 'MK',
            "{$prefix}CName" => 'Северна Македонија',
            "{$prefix}Tin" => $taxId,
            "{$prefix}ForeignTin" => null,
            // "МК" here is the Cyrillic М/К (U+041C/U+041A), confirmed against a real
            // UJP-supplied example ("sellerVatNumber": "МК4030995135699" in
            // primer_za_json_3.pdf) — not the visually-identical Latin "MK".
            "{$prefix}VatNumber" => ($isVatRegistered && $taxId) ? "\u{041C}\u{041A}".$taxId : null,
            "{$prefix}Name" => $name,
            "{$prefix}Address" => [
                'streetAddress' => $streetAddress ?? '',
                'streetNumber' => $streetNumber ?? '',
                'postalCode' => $postalCode ?? '',
                'city' => $city ?? '',
            ],
            "{$prefix}Contact" => null,
            "{$prefix}Email" => null,
        ];
    }

    private function buildItem($line, int $lineNo): array
    {
        $qty = (float) $line->quantity;
        $unitPrice = (float) $line->unit_price;
        $lineTotal = (float) $line->lineTotal();
        $vatAmount = (float) $line->vatAmount();
        $vatPercent = EfakturaTaxIndicator::percent($line->vat_treatment, (string) $line->vat_rate);
        $taxIndicator = EfakturaTaxIndicator::code($line->vat_treatment, (string) $line->vat_rate);
        // docItemUnitVat is the per-unit VAT AMOUNT (unitPrice * rate/100), not the rate —
        // confirmed against primer_za_json_3.pdf (unit price 95.2381 @ 5% -> 4.7619, while
        // docItemVat, a separate field, carries the rate itself, 5). A round unit price
        // like 100.00 makes the two coincide by chance, which is why this was wrong before.
        $unitVatAmount = $unitPrice * $vatPercent / 100;

        return [
            'docItemLineNo' => $lineNo,
            'docItemSku' => $line->item?->code,
            'docItemSenderCode' => $line->item?->code,
            'docItemReceiverCode' => null,
            'docItemDesc' => $line->description ?: $line->item?->name,
            'docItemMUnit' => $line->item?->unit_of_measure ?: 'бр.',
            'docItemQty' => $qty,
            'docItemUnitOriginalPriceWoVat' => $unitPrice,
            'docItemUnitDiscountAmount' => 0,
            'docItemUnitPriceWoVat' => $unitPrice,
            'docItemUnitVat' => $unitVatAmount,
            'docItemVat' => $vatPercent,
            'docItemVatGroup' => $taxIndicator,
            'docItemTotalOriginalPriceWoVat' => $lineTotal,
            'docItemTotalPriceWoVat' => $lineTotal,
            'docItemTotalVat' => $vatAmount,
            'docItemTotalPriceWVat' => round($lineTotal + $vatAmount, 2),
            'docItemTaxIndicator' => $taxIndicator,
            'docItemDomesticProduct' => null,
        ];
    }

    private function buildVatTotals(SalesInvoice $invoice): array
    {
        return $invoice->lines
            ->groupBy(fn ($line) => EfakturaTaxIndicator::code($line->vat_treatment, (string) $line->vat_rate))
            ->map(function ($lines, $code) {
                $percent = EfakturaTaxIndicator::percent($lines->first()->vat_treatment, (string) $lines->first()->vat_rate);
                $base = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->lineTotal(), 10), '0');
                $vat = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->vatAmount(), 10), '0');
                $base = (float) Bcmath::roundHalfUp($base, 2);
                $vat = (float) Bcmath::roundHalfUp($vat, 2);

                return [
                    'vatTaxIndicator' => $code,
                    'vatTaxIndicatorNote' => $code === 'DDV-G' ? 'не е ДДВ обврзник' : '',
                    'vatCode' => $code,
                    'vatPercent' => $percent,
                    'vatTaxableAmount' => $base,
                    'vatAmount' => $vat,
                    'vatTotalAmount' => round($base + $vat, 2),
                ];
            })
            ->values()
            ->all();
    }
}
