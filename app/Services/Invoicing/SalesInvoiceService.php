<?php

namespace App\Services\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\DB;

class SalesInvoiceService
{
    public function __construct(private StockMovementService $stockMovementService)
    {
    }

    public function confirm(SalesInvoice $invoice, int $userId): SalesInvoice
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidInvoiceStateException("Invoice #{$invoice->id} is not a draft and cannot be confirmed.");
        }

        $invoice->loadMissing(['lines', 'company']);

        if ($invoice->lines->isEmpty()) {
            throw new InvalidInvoiceStateException('An invoice needs at least one line before it can be confirmed.');
        }

        $hasItemLines = $invoice->lines->contains(fn ($line) => $line->item_id !== null);

        if ($hasItemLines && $invoice->warehouse_id === null) {
            throw new InvalidInvoiceStateException('A warehouse is required to confirm an invoice with item lines.');
        }

        return DB::transaction(function () use ($invoice, $userId) {
            $fiscalYear = $invoice->invoice_date->year;
            $maxNumber = SalesInvoice::where('company_id', $invoice->company_id)
                ->where('fiscal_year', $fiscalYear)
                ->lockForUpdate()
                ->max('invoice_number');
            $invoiceNumber = ($maxNumber ?? 0) + 1;

            $cogsTotal = '0.00';

            foreach ($invoice->lines as $line) {
                if ($line->item_id === null) {
                    continue;
                }

                $movement = $this->stockMovementService->issue(
                    $line->item,
                    $invoice->warehouse,
                    (string) $line->quantity,
                    $invoice->invoice_date->toDateString(),
                    $userId
                );

                $line->update(['stock_movement_id' => $movement->id]);
                $cogsTotal = bcadd($cogsTotal, bcmul((string) $line->quantity, (string) $movement->unit_cost, 2), 2);
            }

            $vatRegistered = $invoice->company->is_vat_registered;
            $net = $invoice->subtotal();
            $vat = $vatRegistered ? $invoice->vatTotal() : '0.00';
            $gross = bcadd($net, $vat, 2);
            $label = "Invoice {$fiscalYear}/{$invoiceNumber}";

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'entry_date' => $invoice->invoice_date,
                'description' => "Sales {$label}",
                'created_by' => $userId,
            ]);

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, '120')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'debit' => $gross,
                'credit' => '0',
            ]);

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, '740')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'debit' => '0',
                'credit' => $net,
            ]);

            if (bccomp($vat, '0', 2) > 0) {
                $entry->lines()->create([
                    'account_id' => $this->account($invoice->company, '230')->id,
                    'partner_id' => $invoice->partner_id,
                    'description' => "VAT on {$label}",
                    'debit' => '0',
                    'credit' => $vat,
                ]);
            }

            if (bccomp($cogsTotal, '0', 2) > 0) {
                $entry->lines()->create([
                    'account_id' => $this->account($invoice->company, '701')->id,
                    'description' => "COGS for {$label}",
                    'debit' => $cogsTotal,
                    'credit' => '0',
                ]);

                $entry->lines()->create([
                    'account_id' => $this->account($invoice->company, '660')->id,
                    'description' => "COGS for {$label}",
                    'debit' => '0',
                    'credit' => $cogsTotal,
                ]);
            }

            $invoice->update([
                'fiscal_year' => $fiscalYear,
                'invoice_number' => $invoiceNumber,
                'journal_entry_id' => $entry->id,
                'status' => 'confirmed',
            ]);

            return $invoice->fresh(['lines', 'payments']);
        });
    }

    private function account(Company $company, string $code): Account
    {
        return Account::where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }
}
