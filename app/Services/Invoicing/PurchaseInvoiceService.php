<?php

namespace App\Services\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    public function __construct(private StockMovementService $stockMovementService)
    {
    }

    public function confirm(PurchaseInvoice $invoice, int $userId): PurchaseInvoice
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidInvoiceStateException("Purchase invoice #{$invoice->id} is not a draft and cannot be confirmed.");
        }

        $invoice->loadMissing(['lines', 'company']);

        if ($invoice->lines->isEmpty()) {
            throw new InvalidInvoiceStateException('A purchase invoice needs at least one line before it can be confirmed.');
        }

        $hasItemLines = $invoice->lines->contains(fn ($line) => $line->item_id !== null);

        if ($hasItemLines && $invoice->warehouse_id === null) {
            throw new InvalidInvoiceStateException('A warehouse is required to confirm a purchase invoice with item lines.');
        }

        return DB::transaction(function () use ($invoice, $userId) {
            $invoice->loadMissing(['lines.account', 'lines.item', 'partner', 'company']);

            $vatRegistered = $invoice->company->is_vat_registered;
            $vatTotal = '0.00';
            $debitsByAccountId = [];

            foreach ($invoice->lines as $line) {
                $lineNet = $line->lineTotal();
                $lineVat = $vatRegistered ? $line->vatAmount() : '0.00';
                $deductible = $vatRegistered && $line->vat_deductible;

                if ($line->item_id !== null) {
                    $movement = $this->stockMovementService->receipt(
                        $line->item,
                        $invoice->warehouse,
                        (string) $line->quantity,
                        (string) $line->unit_price,
                        $invoice->invoice_date->toDateString(),
                        $userId
                    );

                    $line->update(['stock_movement_id' => $movement->id]);
                    $targetAccount = $this->account($invoice->company, '660');
                } else {
                    $targetAccount = $line->account;
                }

                $debitAmount = $deductible ? $lineNet : bcadd($lineNet, $lineVat, 2);
                $debitsByAccountId[$targetAccount->id] = bcadd($debitsByAccountId[$targetAccount->id] ?? '0.00', $debitAmount, 2);

                if ($deductible) {
                    $vatTotal = bcadd($vatTotal, $lineVat, 2);
                }
            }

            $supplierRef = "{$invoice->partner->name} #{$invoice->supplier_invoice_number}";
            $label = "Purchase bill {$supplierRef}";

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'entry_date' => $invoice->invoice_date,
                'description' => $label,
                'created_by' => $userId,
            ]);

            $grossTotal = '0.00';

            foreach ($debitsByAccountId as $accountId => $amount) {
                $entry->lines()->create([
                    'account_id' => $accountId,
                    'partner_id' => $invoice->partner_id,
                    'description' => $label,
                    'debit' => $amount,
                    'credit' => '0',
                ]);
                $grossTotal = bcadd($grossTotal, $amount, 2);
            }

            if (bccomp($vatTotal, '0', 2) > 0) {
                $entry->lines()->create([
                    'account_id' => $this->account($invoice->company, '130')->id,
                    'partner_id' => $invoice->partner_id,
                    'description' => "Input VAT on {$label}",
                    'debit' => $vatTotal,
                    'credit' => '0',
                ]);
                $grossTotal = bcadd($grossTotal, $vatTotal, 2);
            }

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, '220')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'debit' => '0',
                'credit' => $grossTotal,
            ]);

            $invoice->update([
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
