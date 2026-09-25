<?php

namespace App\Services\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\ProformaInvoice;
use App\Support\InvoiceNumber;
use Illuminate\Support\Facades\DB;

class ProformaService
{
    /**
     * Профактурата се нумерира при првото зачувување, не при потврда —
     * бројот се гледа на екранот и на PDF-от од самиот почеток. Серијата е
     * одвоена од фактурите и во иста логика: со година во бројот се брои по
     * година, без година тече непрекинато.
     *
     * @param  array<string, mixed>  $attributes  без број; тука се доделува
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function create(Company $company, array $attributes, array $lines): ProformaInvoice
    {
        return DB::transaction(function () use ($company, $attributes, $lines) {
            $fiscalYear = (int) date('Y', strtotime((string) $attributes['proforma_date']));

            $query = ProformaInvoice::where('company_id', $company->id);

            if ($company->invoice_number_include_year) {
                $query->where('fiscal_year', $fiscalYear);
            }

            $next = ($query->lockForUpdate()->max('proforma_number') ?? 0) + 1;

            $proforma = ProformaInvoice::create($attributes + [
                'company_id' => $company->id,
                'fiscal_year' => $fiscalYear,
                'proforma_number' => $next,
                'proforma_number_formatted' => InvoiceNumber::format($company, $fiscalYear, $next, (string) ($company->proforma_number_prefix ?? '')),
            ]);

            $proforma->lines()->createMany($lines);

            return $proforma->load('lines');
        });
    }

    /** @param  array<string, mixed>  $attributes */
    public function update(ProformaInvoice $proforma, array $attributes, array $lines): ProformaInvoice
    {
        if (! $proforma->isOpen()) {
            throw new InvalidInvoiceStateException('Претворена или откажана профактура не може да се менува.');
        }

        return DB::transaction(function () use ($proforma, $attributes, $lines) {
            $proforma->update($attributes);
            $proforma->lines()->delete();
            $proforma->lines()->createMany($lines);

            return $proforma->load('lines');
        });
    }

    public function confirm(ProformaInvoice $proforma): ProformaInvoice
    {
        if ($proforma->status !== 'draft') {
            throw new InvalidInvoiceStateException('Само нацрт може да се потврди.');
        }

        $proforma->loadMissing('lines');

        if ($proforma->lines->isEmpty()) {
            throw new InvalidInvoiceStateException('Профактурата мора да има барем една ставка.');
        }

        $proforma->update(['status' => 'confirmed']);

        return $proforma;
    }

    public function cancel(ProformaInvoice $proforma): ProformaInvoice
    {
        if (! $proforma->isOpen()) {
            throw new InvalidInvoiceStateException('Претворена профактура не може да се откаже — веќе е фактурирана.');
        }

        $proforma->update(['status' => 'cancelled']);

        return $proforma;
    }
}
