<?php

namespace App\Services\Posting;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\JournalGroup;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Налозите на фактурите. Сите излезни фактури од еден месец се ставки на ЕДЕН
 * налог во група 30 (30-0001 = јануари, 30-0002 = февруари …), а сите влезни
 * во група 20 на ист начин. Месецот го одредува датумот на издавање.
 *
 * Фактурата сама покажува кон налогот (`journal_entry_id`), но налогот е
 * заеднички — затоа секоја нејзина ставка носи `source_type`/`source_id`, за
 * да може фактурата да се најде, смени или избрише без да се допре туѓата.
 */
class InvoiceJournal
{
    public const SALES = 'sales_invoice';

    public const PURCHASE = 'purchase_invoice';

    private const KINDS = [
        self::SALES => ['code' => '30', 'name' => 'Излезни фактури', 'sort' => 30],
        self::PURCHASE => ['code' => '20', 'name' => 'Влезни фактури', 'sort' => 20],
    ];

    private const MONTHS = [
        1 => 'јануари', 2 => 'февруари', 3 => 'март', 4 => 'април', 5 => 'мај', 6 => 'јуни',
        7 => 'јули', 8 => 'август', 9 => 'септември', 10 => 'октомври', 11 => 'ноември', 12 => 'декември',
    ];

    public static function kindOf(SalesInvoice|PurchaseInvoice $invoice): string
    {
        return $invoice instanceof SalesInvoice ? self::SALES : self::PURCHASE;
    }

    /**
     * Ги запишува ставките на фактурата во месечниот налог (го создава ако
     * го нема). Мора да се вика во трансакција — налогот се заклучува.
     *
     * @param  iterable<\App\Support\Posting\PostingLine>  $lines
     */
    public function post(SalesInvoice|PurchaseInvoice $invoice, iterable $lines, int $userId): JournalEntry
    {
        $kind = self::kindOf($invoice);
        $date = Carbon::parse($invoice->invoice_date);
        $entry = $this->monthlyEntry($invoice->company, $kind, $date, $userId);

        foreach ($lines as $line) {
            $entry->lines()->create(array_merge($line->journalColumns($date), [
                'source_type' => $kind,
                'source_id' => $invoice->id,
            ]));
        }

        return $entry;
    }

    public function monthlyEntry(Company $company, string $kind, Carbon $date, int $userId): JournalEntry
    {
        $config = self::KINDS[$kind];

        $group = JournalGroup::firstOrCreate(
            ['company_id' => $company->id, 'code' => $config['code']],
            ['name' => $config['name'], 'sort_order' => $config['sort']]
        );

        $find = fn () => JournalEntry::where('company_id', $company->id)
            ->where('journal_group_id', $group->id)
            ->where('auto_kind', $kind)
            ->whereDate('entry_date', '>=', $date->copy()->startOfMonth()->toDateString())
            ->whereDate('entry_date', '<=', $date->copy()->endOfMonth()->toDateString())
            ->lockForUpdate()
            ->first();

        $entry = $find();

        if ($entry !== null) {
            return $entry;
        }

        $entry = new JournalEntry([
            'company_id' => $company->id,
            'journal_group_id' => $group->id,
            'entry_date' => $date->copy()->endOfMonth()->toDateString(),
            'description' => $config['name'].' — '.self::MONTHS[$date->month].' '.$date->year,
            'created_by' => $userId,
        ]);
        $entry->auto_kind = $kind;

        // Бројот на налогот е бројот на месецот. Ако некој рачно веќе го зафатил
        // тој број во групата, налогот го добива првиот слободен (креаторот).
        $monthNumberTaken = JournalEntry::where('company_id', $company->id)
            ->where('journal_group_id', $group->id)
            ->where('fiscal_year', $date->year)
            ->where('entry_number', $date->month)
            ->exists();

        if (! $monthNumberTaken) {
            $entry->entry_number = $date->month;
        }

        try {
            $entry->save();
        } catch (UniqueConstraintViolationException) {
            // Истовремена потврда ја создала првата — земи ја неа.
            return $find() ?? throw new \RuntimeException('Месечниот налог не може да се создаде.');
        }

        return $entry;
    }

    /**
     * Ги отстранува ставките на фактурата. Се вика ОТКАКО фактурата е избришана
     * (таа покажува кон налогот, не обратно). Празен месечен налог се брише.
     * Налог од старото книжење (еден налог = една фактура) се брише целосен.
     */
    public function remove(string $kind, int $invoiceId, ?JournalEntry $entry): void
    {
        if ($entry === null) {
            return;
        }

        if ($entry->auto_kind === null) {
            $entry->delete();

            return;
        }

        JournalEntryLine::where('journal_entry_id', $entry->id)
            ->where('source_type', $kind)
            ->where('source_id', $invoiceId)
            ->delete();

        if (! $entry->lines()->exists()) {
            $entry->documents()->delete();
            $entry->delete();
        }
    }

    /** Ставките на самата фактура (не на целиот месечен налог). */
    public function linesOf(SalesInvoice|PurchaseInvoice $invoice): Collection
    {
        $entry = $invoice->journalEntry;

        if ($entry === null) {
            return collect();
        }

        $query = $entry->lines()->with('account');

        if ($entry->auto_kind !== null) {
            $query->where('source_type', self::kindOf($invoice))->where('source_id', $invoice->id);
        }

        return $query->get();
    }
}
