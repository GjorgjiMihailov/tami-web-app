<?php

namespace App\Services\Posting;

use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Старите автоматски налози (еден налог = една фактура, група 99) се
 * собираат во месечните налози на групите 30 и 20. Само се преместуваат
 * ставките — ниту еден износ, конто, партнер или датум не се менува, па
 * салдата и пробниот биланс остануваат исти.
 *
 * Безбедно е да се повика повеќепати: допира само фактури чиј налог е
 * старото книжење (без `auto_kind`, во група 99, и без друга фактура на него).
 */
class InvoiceJournalConsolidator
{
    public function __construct(private InvoiceJournal $journal) {}

    /** @return array{sales: int, purchase: int, skipped: int} */
    public function run(): array
    {
        $moved = [InvoiceJournal::SALES => 0, InvoiceJournal::PURCHASE => 0];
        $skipped = 0;

        $sources = [
            InvoiceJournal::SALES => SalesInvoice::class,
            InvoiceJournal::PURCHASE => PurchaseInvoice::class,
        ];

        foreach ($sources as $kind => $model) {
            $model::query()
                ->whereNotNull('journal_entry_id')
                ->whereHas('journalEntry', fn ($entry) => $entry->whereNull('auto_kind'))
                ->with('journalEntry.journalGroup')
                // По id, не со страници по поместување: филтерот се менува додека
                // се оди (преместената фактура повеќе не го исполнува).
                ->lazyById(200)
                ->each(function ($invoice) use ($kind, &$moved, &$skipped) {
                    if ($this->consolidate($invoice, $kind)) {
                        $moved[$kind]++;
                    } else {
                        $skipped++;
                    }
                });
        }

        return ['sales' => $moved[InvoiceJournal::SALES], 'purchase' => $moved[InvoiceJournal::PURCHASE], 'skipped' => $skipped];
    }

    private function consolidate(SalesInvoice|PurchaseInvoice $invoice, string $kind): bool
    {
        $old = $invoice->journalEntry;

        // Само автоматските налози на фактури (група 99). Налог создаден рачно
        // (или во друга група) не се допира.
        if ($old === null || $old->journalGroup?->code !== '99') {
            return false;
        }

        // Налогот мора да е само на оваа фактура.
        $sharedBySales = SalesInvoice::where('journal_entry_id', $old->id)->whereKeyNot($kind === InvoiceJournal::SALES ? $invoice->id : 0)->exists();
        $sharedByPurchase = PurchaseInvoice::where('journal_entry_id', $old->id)->whereKeyNot($kind === InvoiceJournal::PURCHASE ? $invoice->id : 0)->exists();

        if ($sharedBySales || $sharedByPurchase) {
            return false;
        }

        DB::transaction(function () use ($invoice, $kind, $old) {
            $target = $this->journal->monthlyEntry(
                $invoice->company,
                $kind,
                Carbon::parse($invoice->invoice_date),
                $old->created_by,
            );

            $oldDate = $old->entry_date->toDateString();

            foreach ($old->lines()->get() as $line) {
                $line->update([
                    'journal_entry_id' => $target->id,
                    'source_type' => $kind,
                    'source_id' => $invoice->id,
                    // Старите ставки може да немаат свој датум — тогаш го носат
                    // датумот на старот налог, за извештаите да не се поместат.
                    'line_date' => $line->line_date ?? $oldDate,
                ]);
            }

            $old->documents()->update([
                'documentable_id' => $target->id,
            ]);

            $invoice->update(['journal_entry_id' => $target->id]);

            JournalEntry::whereKey($old->id)->delete();
        });

        return true;
    }
}
