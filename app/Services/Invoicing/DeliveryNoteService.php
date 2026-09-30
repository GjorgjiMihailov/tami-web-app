<?php

namespace App\Services\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\DeliveryNote;
use App\Models\ProformaInvoice;
use App\Models\SalesInvoice;
use App\Support\InvoiceNumber;
use Illuminate\Support\Facades\DB;

class DeliveryNoteService
{
    /**
     * Секој извор (профактура или фактура) добива најмногу една испратница —
     * повторен повик за истиот извор ја враќа истата, наместо да создава нова
     * со нов број. Уникатниот индекс на (deliverable_type, deliverable_id) е
     * заштитата ако два повика се случат во исто време.
     */
    public function createOrGetFor(ProformaInvoice|SalesInvoice $source): DeliveryNote
    {
        if ($source->status !== 'confirmed') {
            throw new InvalidInvoiceStateException('Испратница може да се издаде само од потврдена профактура или фактура.');
        }

        $existing = DeliveryNote::where('deliverable_type', $source::class)
            ->where('deliverable_id', $source->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $company = $source->company;

        return DB::transaction(function () use ($company, $source) {
            $fiscalYear = (int) now()->year;

            $query = DeliveryNote::where('company_id', $company->id);

            if ($company->invoice_number_include_year) {
                $query->where('fiscal_year', $fiscalYear);
            }

            $next = ($query->lockForUpdate()->max('delivery_note_number') ?? 0) + 1;

            return DeliveryNote::create([
                'company_id' => $company->id,
                'deliverable_type' => $source::class,
                'deliverable_id' => $source->id,
                'fiscal_year' => $fiscalYear,
                'delivery_note_number' => $next,
                'delivery_note_number_formatted' => InvoiceNumber::format($company, $fiscalYear, $next, (string) ($company->delivery_note_number_prefix ?? '')),
                'delivery_date' => now()->toDateString(),
                'created_by' => auth()->id(),
            ]);
        });
    }
}
