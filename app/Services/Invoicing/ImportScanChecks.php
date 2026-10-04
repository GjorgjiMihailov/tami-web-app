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
                $invoiceCurrency = $this->currency($invoice->currency);
                $ecdCurrency = $this->currency($ecd->currency);
                $currenciesDiffer = $invoiceCurrency !== null && $ecdCurrency !== null && $invoiceCurrency !== $ecdCurrency;

                if ($currenciesDiffer) {
                    $warnings[] = "Валутата на фактурата ({$invoiceCurrency}) не е иста со валутата на ЕЦД ({$ecdCurrency}).";
                }

                // Со различни валути споредбата на износите не значи ништо
                // (втор, залажувачки предупредувач).
                if (! $currenciesDiffer && $invoice->printedTotal !== null && $ecd->invoiceTotalForeign !== null
                    && Bcmath::isPlainNumber($invoice->printedTotal) && Bcmath::isPlainNumber($ecd->invoiceTotalForeign)
                    && bccomp($invoice->printedTotal, $ecd->invoiceTotalForeign, 2) !== 0) {
                    $difference = ltrim(bcsub($invoice->printedTotal, $ecd->invoiceTotalForeign, 2), '-');
                    $warnings[] = "Вкупно на фактурата ({$invoice->printedTotal}) не е исто со вредноста во ЕЦД ({$ecd->invoiceTotalForeign}) — разлика {$difference}.";
                }

                if ($invoice->invoiceNumber !== null && $ecd->referencedInvoiceNumbers !== []
                    && ! $this->numberReferenced($invoice->invoiceNumber, $ecd->referencedInvoiceNumbers)) {
                    $warnings[] = "Бројот на фактурата ({$invoice->invoiceNumber}) не се спомнува во ЕЦД (поле 44).";
                }
            }

            if ($forwarder !== null && filled($ecd->declarantName) && filled($forwarder->sellerName)
                && ! $this->namesOverlap((string) $ecd->declarantName, (string) $forwarder->sellerName)) {
                $warnings[] = "Шпедитерот на ЕЦД ({$ecd->declarantName}) не се совпаѓа со издавачот на шпедитерската фактура ({$forwarder->sellerName}).";
            }

            if ($forwarder !== null && $this->forwarderRepeatsDuty($forwarder, $summary['duty_total'], $summary['vat_total'])) {
                $warnings[] = 'Шпедитерската фактура содржи износ еднаков со царината/ДДВ од ЕЦД — ако е пренесена царина, ќе се смета двапати во магацинската вредност.';
            }
        }

        if ($invoice !== null && ($sum = $this->linesGrossSum($invoice)) !== null
            && Bcmath::isPlainNumber($invoice->printedTotal)
            && bccomp(ltrim(bcsub($sum, $invoice->printedTotal, 2), '-'), '0.05', 2) > 0) {
            $warnings[] = "Збирот на ставките на фактурата ({$sum}) не е ист со вкупното испишано ({$invoice->printedTotal}) — можеби е изоставена или погрешно прочитана ставка.";
        }

        if ($rateFromNbrm) {
            $warnings[] = 'Курсот е од НБРМ на датумот на фактурата — нема ЕЦД со курс од декларацијата.';
        }

        return $warnings;
    }

    private function differs(string $computed, ?string $printed): bool
    {
        return Bcmath::isPlainNumber($printed) && bccomp($computed, $printed, 2) !== 0;
    }

    private function currency(?string $currency): ?string
    {
        $value = strtoupper(trim((string) $currency));

        return $value === '' ? null : $value;
    }

    /**
     * Поле 44 може да врати подолг стринг од бројот на фактурата (или обратно),
     * па важи и содржување — но само за број од барем 3 знаци, за да „1“ не
     * се совпадне со сè.
     *
     * @param  string[]  $referenced
     */
    private function numberReferenced(string $number, array $referenced): bool
    {
        $mine = $this->normalizeNumber($number);

        foreach ($referenced as $other) {
            $theirs = $this->normalizeNumber($other);

            if ($mine === $theirs) {
                return true;
            }

            if (min(mb_strlen($mine), mb_strlen($theirs)) >= 3
                && (str_contains($mine, $theirs) || str_contains($theirs, $mine))) {
                return true;
            }
        }

        return false;
    }

    /** Σ(количина × цена × (1 + ДДВ/100)), по ставка на 2 децимали; null ако некоја ставка не е читлива. */
    private function linesGrossSum(ScannedInvoice $invoice): ?string
    {
        if ($invoice->lines === []) {
            return null;
        }

        $sum = '0.00';

        foreach ($invoice->lines as $line) {
            $vat = filled($line->vatRate) ? $line->vatRate : '0';

            if (! Bcmath::isPlainNumber($line->quantity) || ! Bcmath::isPlainNumber($line->unitPrice) || ! Bcmath::isPlainNumber($vat)) {
                return null;
            }

            $factor = bcadd('1', bcdiv($vat, '100', 12), 12);
            $sum = bcadd($sum, Bcmath::roundHalfUp(bcmul(bcmul($line->quantity, $line->unitPrice, 12), $factor, 12), 2), 2);
        }

        return $sum;
    }

    /** Ставка на шпедитерската со износ (±0,50) еднаков на царината или ДДВ од ЕЦД. */
    private function forwarderRepeatsDuty(ScannedInvoice $forwarder, string $dutyTotal, string $vatTotal): bool
    {
        foreach ($forwarder->lines as $line) {
            if (! Bcmath::isPlainNumber($line->quantity) || ! Bcmath::isPlainNumber($line->unitPrice)) {
                continue;
            }

            $total = Bcmath::roundHalfUp(bcmul($line->quantity, $line->unitPrice, 12), 2);

            foreach ([$dutyTotal, $vatTotal] as $reference) {
                if (bccomp($reference, '0', 2) > 0
                    && bccomp(ltrim(bcsub($total, $reference, 2), '-'), '0.50', 2) <= 0) {
                    return true;
                }
            }
        }

        return false;
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
