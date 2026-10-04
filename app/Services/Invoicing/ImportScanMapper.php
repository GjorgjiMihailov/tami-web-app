<?php

namespace App\Services\Invoicing;

use App\Support\Bcmath;

/**
 * Преводот на прочитаните увозни документи во облик што формата го разбира.
 * Чиста класа: без Livewire, без база, без мрежа — сè што е нејасно се
 * враќа како предупредување, никогаш не се измислува.
 */
class ImportScanMapper
{
    /** Валутите што формата за увоз ги нуди (иста листа како во Blade). */
    public const CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF'];

    /**
     * Фактурата од странски добавувач во денари по зададениот курс. Ставките
     * „транспорт/осигурување/пакување" (kind=charge) остануваат ставки на
     * фактурата И се враќаат како ред во `costs`.
     *
     * @return array{invoice: ScannedInvoice, costs: array<int, array<string, string>>, warnings: string[]}
     */
    public function convertInvoice(ScannedInvoice $invoice, string $rate): array
    {
        $lines = [];
        $costs = [];
        $warnings = [];

        foreach ($invoice->lines as $index => $line) {
            $position = $index + 1;

            if ($line->kind === 'charge') {
                $foreign = $this->lineTotal($line);

                if ($foreign === null) {
                    // Ставката останува на фактурата (долгот кон добавувачот мора да е
                    // колку на хартијата), но непретворена, со предупредување.
                    $warnings[] = "Ставка {$position} („{$line->description}“): износот не е читлив и не е претворен во денари — внеси го трошокот и износот рачно.";
                    $lines[] = $line;

                    continue;
                }

                // Двојно, по одлука на сопственикот: ставка на фактурата (сметка 660,
                // за да не се изгуби од долгот кон добавувачот) И ред „Увозни
                // трошоци" (за да влезе во магацинската вредност).
                $costs[] = [
                    'payee_name' => (string) ($invoice->sellerName ?? ''),
                    'reference_number' => (string) ($invoice->invoiceNumber ?? ''),
                    'foreign_amount' => $foreign,
                    'base_amount' => Bcmath::roundHalfUp(bcmul($foreign, $rate, 12), 2),
                    'vat_amount' => '0.00',
                    'source' => 'invoice',
                ];
            }

            if (! Bcmath::isPlainNumber($line->unitPrice)) {
                $warnings[] = "Ставка {$position} („{$line->description}“): цената не е читлива и не е претворена во денари.";
                $lines[] = $line;

                continue;
            }

            $lines[] = new ScannedInvoiceLine(
                description: $line->description,
                quantity: $line->quantity,
                unitPrice: Bcmath::roundHalfUp(bcmul($line->unitPrice, $rate, 12), 2),
                vatRate: '0',
                kind: $line->kind,
            );
        }

        return [
            'invoice' => $this->copyWith($invoice, $lines),
            'costs' => $costs,
            'warnings' => $warnings,
        ];
    }

    /**
     * Шпедитерската фактура како еден ред „Увозни трошоци" (денарска).
     *
     * @return array{row: array<string, string>, warnings: string[]}
     */
    public function forwarderCost(ScannedInvoice $forwarder): array
    {
        $warnings = [];
        $net = '0.00';
        $vat = '0.00';

        foreach ($forwarder->lines as $index => $line) {
            $total = $this->lineTotal($line);

            if ($total === null) {
                $warnings[] = 'Шпедитерска фактура, ставка '.($index + 1).': износот не е читлив — провери го редот.';

                continue;
            }

            $rate = Bcmath::isPlainNumber($line->vatRate) ? $line->vatRate : '0';

            $net = bcadd($net, $total, 2);
            $vat = bcadd($vat, Bcmath::roundHalfUp(bcdiv(bcmul($total, $rate, 12), '100', 12), 2), 2);
        }

        if ($forwarder->currency !== null && $forwarder->currency !== 'MKD') {
            $warnings[] = "Шпедитерската фактура е во {$forwarder->currency}, не во денари — износите не се претворени, провери го редот.";
        }

        return [
            'row' => [
                'payee_name' => (string) ($forwarder->sellerName ?? ''),
                'reference_number' => (string) ($forwarder->invoiceNumber ?? ''),
                'foreign_amount' => '',
                'base_amount' => $net,
                'vat_amount' => $vat,
                'source' => 'forwarder',
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{customsDeclarationNumber: string, importDate: string, importCurrencyCode: ?string, importExchangeRate: string}
     */
    public function declarationFields(ScannedCustomsDeclaration $declaration): array
    {
        return [
            'customsDeclarationNumber' => (string) ($declaration->declarationNumber ?? ''),
            'importDate' => (string) ($declaration->date ?? ''),
            'importCurrencyCode' => in_array($declaration->currency, self::CURRENCIES, true) ? $declaration->currency : null,
            'importExchangeRate' => (string) ($declaration->exchangeRate ?? ''),
        ];
    }

    private function lineTotal(ScannedInvoiceLine $line): ?string
    {
        if (! Bcmath::isPlainNumber($line->quantity) || ! Bcmath::isPlainNumber($line->unitPrice)) {
            return null;
        }

        return Bcmath::roundHalfUp(bcmul($line->quantity, $line->unitPrice, 12), 2);
    }

    /** @param ScannedInvoiceLine[] $lines */
    private function copyWith(ScannedInvoice $i, array $lines): ScannedInvoice
    {
        return new ScannedInvoice(
            sellerTaxId: $i->sellerTaxId,
            buyerName: $i->buyerName,
            buyerTaxId: $i->buyerTaxId,
            buyerStreetAddress: $i->buyerStreetAddress,
            buyerStreetNumber: $i->buyerStreetNumber,
            buyerPostalCode: $i->buyerPostalCode,
            buyerCity: $i->buyerCity,
            invoiceNumber: $i->invoiceNumber,
            invoiceDate: $i->invoiceDate,
            dueDate: $i->dueDate,
            currency: 'MKD',
            printedTotal: $i->printedTotal,
            lines: $lines,
            sellerName: $i->sellerName,
            invoiceCount: $i->invoiceCount,
            ourCompanyRole: $i->ourCompanyRole,
        );
    }
}
