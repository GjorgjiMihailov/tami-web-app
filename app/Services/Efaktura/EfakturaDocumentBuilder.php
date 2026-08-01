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
            'requestTimestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
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
                'seller' => $this->buildParty($company->name, $company->tax_id, $company->street_address, $company->street_number, $company->postal_code, $company->city, 'seller'),
                'buyer' => $this->buildParty($partner->name, $partner->tax_id, $partner->street_address, $partner->street_number, $partner->postal_code, $partner->city, 'buyer'),
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

    private function buildParty(?string $name, ?string $taxId, ?string $streetAddress, ?string $streetNumber, ?string $postalCode, ?string $city, string $prefix): array
    {
        return [
            "{$prefix}CCode" => 'MK',
            "{$prefix}CName" => 'Северна Македонија',
            "{$prefix}Tin" => $taxId,
            "{$prefix}ForeignTin" => null,
            "{$prefix}VatNumber" => $taxId ? 'МК'.$taxId : null,
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
            'docItemUnitVat' => $vatPercent,
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
                    'vatTaxIndicatorNote' => '',
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
