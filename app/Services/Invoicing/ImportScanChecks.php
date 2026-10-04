<?php

namespace App\Services\Invoicing;

use App\Services\Inventory\CustomsTariffAggregator;
use App\Support\Bcmath;

/**
 * Проверки врз прочитаните увозни документи. Враќа листа предупредувања —
 * ништо не блокира: човекот е тој што одлучува, а скенот е фотографија.
 * Чиста класа без база и мрежа.
 */
class ImportScanChecks
{
    /**
     * @return string[]
     */
    public function run(
        ?ScannedCustomsDeclaration $ecd,
        ?ScannedInvoice $invoice,
        ?ScannedInvoice $forwarder,
        string $companyTaxId,
        bool $rateFromNbrm,
    ): array {
        $warnings = [];

        if ($ecd !== null) {
            $summary = (new CustomsTariffAggregator)->aggregate($ecd->items);

            if ($this->differs($summary['duty_total'], $ecd->totalDuty)) {
                $warnings[] = "Збирот на царината по ставка ({$summary['duty_total']}) не е ист со ВКУПНО од ЕЦД ({$ecd->totalDuty}) — провери ги ставките.";
            }

            if ($this->differs($summary['vat_total'], $ecd->totalVat)) {
                $warnings[] = "Збирот на ДДВ по ставка ({$summary['vat_total']}) не е ист со ВКУПНО од ЕЦД ({$ecd->totalVat}) — провери ги ставките.";
            }

            if ($this->differs($summary['foreign_total'], $ecd->invoiceTotalForeign)) {
                $warnings[] = "Збирот на фактурните вредности по ставка ({$summary['foreign_total']}) не е ист со вкупната вредност од ЕЦД ({$ecd->invoiceTotalForeign}) — провери ги ставките.";
            }

            if ($summary['other_codes'] !== []) {
                $codes = implode(', ', $summary['other_codes']);
                $warnings[] = "ЕЦД содржи и други давачки ({$codes}) освен царина (A00) и ДДВ (B00) — тие НЕ се пренесени, додади ги рачно ако треба.";
            }

            $ours = preg_replace('/\D+/', '', $companyTaxId) ?? '';
            $importer = preg_replace('/\D+/', '', (string) $ecd->importerTaxId) ?? '';

            if ($importer !== '' && $ours !== '' && $importer !== $ours) {
                $warnings[] = 'Увозник на ЕЦД не е тековната фирма (ЕДБ '.$importer.') — провери дали е прикачена вистинската декларација.';
            }

            if ($invoice !== null) {
                if ($invoice->currency !== null && $ecd->currency !== null && $invoice->currency !== $ecd->currency) {
                    $warnings[] = "Валутата на фактурата ({$invoice->currency}) не е иста со валутата на ЕЦД ({$ecd->currency}).";
                }

                if ($invoice->printedTotal !== null && $ecd->invoiceTotalForeign !== null
                    && Bcmath::isPlainNumber($invoice->printedTotal) && Bcmath::isPlainNumber($ecd->invoiceTotalForeign)
                    && bccomp($invoice->printedTotal, $ecd->invoiceTotalForeign, 2) !== 0) {
                    $difference = ltrim(bcsub($invoice->printedTotal, $ecd->invoiceTotalForeign, 2), '-');
                    $warnings[] = "Вкупно на фактурата ({$invoice->printedTotal}) не е исто со вредноста во ЕЦД ({$ecd->invoiceTotalForeign}) — разлика {$difference}.";
                }

                if ($invoice->invoiceNumber !== null && $ecd->referencedInvoiceNumbers !== []
                    && ! in_array($this->normalizeNumber($invoice->invoiceNumber), array_map($this->normalizeNumber(...), $ecd->referencedInvoiceNumbers), true)) {
                    $warnings[] = "Бројот на фактурата ({$invoice->invoiceNumber}) не се спомнува во ЕЦД (поле 44).";
                }
            }

            if ($forwarder !== null && filled($ecd->declarantName) && filled($forwarder->sellerName)
                && ! $this->namesOverlap((string) $ecd->declarantName, (string) $forwarder->sellerName)) {
                $warnings[] = "Шпедитерот на ЕЦД ({$ecd->declarantName}) не се совпаѓа со издавачот на шпедитерската фактура ({$forwarder->sellerName}).";
            }
        }

        if ($rateFromNbrm) {
            $warnings[] = 'Нема ЕЦД — курсот е од НБРМ на датумот на фактурата. Со ЕЦД курсот би бил оној од декларацијата.';
        }

        return $warnings;
    }

    private function differs(string $computed, ?string $printed): bool
    {
        return Bcmath::isPlainNumber($printed) && bccomp($computed, $printed, 2) !== 0;
    }

    private function normalizeNumber(string $number): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $number) ?? $number);
    }

    /** Имињата се совпаѓаат ако делат барем еден збор од 4+ знаци (ДООЕЛ и слично не се броат). */
    private function namesOverlap(string $a, string $b): bool
    {
        $words = fn (string $s) => array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)) ?: [],
            fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, ['доел', 'доо', 'дооел', 'скопје', 'doel', 'dooel'], true),
        );

        return array_intersect($words($a), $words($b)) !== [];
    }
}
