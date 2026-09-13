<?php

namespace App\Services\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\SalesInvoice;
use App\Models\SalesInvoicePayment;
use App\Services\Inventory\StockMovementService;
use App\Support\Bcmath;
use App\Support\InvoiceNumber;
use Illuminate\Support\Facades\DB;

class SalesInvoiceService
{
    public function __construct(private StockMovementService $stockMovementService)
    {
    }

    public function confirm(SalesInvoice $invoice, int $userId): SalesInvoice
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidInvoiceStateException("Фактура #{$invoice->id} не е нацрт и не може да се потврди.");
        }

        $invoice->loadMissing(['lines.item', 'company']);

        if ($invoice->lines->isEmpty()) {
            throw new InvalidInvoiceStateException('Фактурата мора да содржи барем една ставка пред да се потврди.');
        }

        $hasStockTrackedLines = $invoice->lines->contains(fn ($line) => $line->item_id !== null && ! $line->item->isService());

        if ($hasStockTrackedLines && $invoice->warehouse_id === null) {
            throw new InvalidInvoiceStateException('Потребен е магацин за потврдување фактура со ставки со артикли.');
        }

        return DB::transaction(function () use ($invoice, $userId) {
            $fiscalYear = $invoice->invoice_date->year;

            // Опсегот на бројачот го диктира форматот. Со година во бројот,
            // сериите се одвојуваат по година како досега. Без година, серијата
            // мора да тече непрекинато — инаку 2027 би почнала пак од 1 и две
            // фактури би носеле ист број.
            $numberQuery = SalesInvoice::where('company_id', $invoice->company_id);

            if ($invoice->company->invoice_number_include_year) {
                $numberQuery->where('fiscal_year', $fiscalYear);
            }

            $maxNumber = $numberQuery->lockForUpdate()->max('invoice_number');
            $invoiceNumber = ($maxNumber ?? 0) + 1;

            $cogsTotal = '0.00';

            foreach ($invoice->lines as $line) {
                if ($line->item_id === null || $line->item->isService()) {
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
                $cogsTotal = bcadd($cogsTotal, Bcmath::roundHalfUp(bcmul((string) $line->quantity, (string) $movement->unit_cost, 10), 2), 2);
            }

            $vatRegistered = $invoice->company->is_vat_registered;
            $net = $this->toMkd($invoice, $invoice->subtotal());
            $vat = $vatRegistered ? $this->toMkd($invoice, $invoice->vatTotal()) : '0.00';
            $gross = bcadd($net, $vat, 2);
            $label = 'Invoice '.InvoiceNumber::format($invoice->company, $fiscalYear, $invoiceNumber);

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,
                'entry_date' => $invoice->invoice_date,
                'description' => "Sales {$label}",
                'created_by' => $userId,
            ]);

            $entry->lines()->create(array_merge([
                'account_id' => $this->account($invoice->company, '120')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'line_date' => $invoice->invoice_date,
                'debit' => $gross,
                'credit' => '0',
            ], $this->currencyColumns($invoice, bcadd($invoice->subtotal(), $vatRegistered ? $invoice->vatTotal() : '0.00', 2))));

            $entry->lines()->create(array_merge([
                'account_id' => $this->account($invoice->company, '740')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'line_date' => $invoice->invoice_date,
                'debit' => '0',
                'credit' => $net,
            ], $this->currencyColumns($invoice, $invoice->subtotal())));

            if (bccomp($vat, '0', 2) > 0) {
                $entry->lines()->create(array_merge([
                    'account_id' => $this->account($invoice->company, '230')->id,
                    'partner_id' => $invoice->partner_id,
                    'description' => "VAT on {$label}",
                    'line_date' => $invoice->invoice_date,
                    'debit' => '0',
                    'credit' => $vat,
                ], $this->currencyColumns($invoice, $invoice->vatTotal())));
            }

            if (bccomp($cogsTotal, '0', 2) > 0) {
                $entry->lines()->create([
                    'account_id' => $this->account($invoice->company, '701')->id,
                    'description' => "COGS for {$label}",
                    'line_date' => $invoice->invoice_date,
                    'debit' => $cogsTotal,
                    'credit' => '0',
                ]);

                $entry->lines()->create([
                    'account_id' => $this->account($invoice->company, '660')->id,
                    'description' => "COGS for {$label}",
                    'line_date' => $invoice->invoice_date,
                    'debit' => '0',
                    'credit' => $cogsTotal,
                ]);
            }

            $invoice->update([
                'fiscal_year' => $fiscalYear,
                'invoice_number' => $invoiceNumber,
                'invoice_number_formatted' => InvoiceNumber::format($invoice->company, $fiscalYear, $invoiceNumber),
                'journal_entry_id' => $entry->id,
                'status' => 'confirmed',
            ]);

            return $invoice->fresh(['lines', 'payments']);
        });
    }

    public function cancel(SalesInvoice $invoice, int $userId): SalesInvoice
    {
        if ($invoice->status !== 'confirmed') {
            throw new InvalidInvoiceStateException("Фактура #{$invoice->id} не е потврдена и не може да се откаже.");
        }

        if ($invoice->payments()->exists()) {
            throw new InvalidInvoiceStateException('Фактура со евидентирани плаќања не може да се откаже.');
        }

        $invoice->loadMissing(['lines.item', 'lines.stockMovement', 'journalEntry.lines', 'warehouse', 'company']);

        return DB::transaction(function () use ($invoice, $userId) {
            foreach ($invoice->lines as $line) {
                if ($line->item_id === null || $line->stockMovement === null) {
                    continue;
                }

                $this->stockMovementService->receipt(
                    $line->item,
                    $invoice->warehouse,
                    (string) $line->quantity,
                    (string) $line->stockMovement->unit_cost,
                    now()->toDateString(),
                    $userId
                );
            }

            $reversal = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,
                'entry_date' => now()->toDateString(),
                'description' => "Reversal of invoice {$invoice->formattedNumber()}",
                'created_by' => $userId,
            ]);

            foreach ($invoice->journalEntry->lines as $originalLine) {
                $reversal->lines()->create([
                    'account_id' => $originalLine->account_id,
                    'partner_id' => $originalLine->partner_id,
                    'description' => 'Reversal: '.$originalLine->description,
                    'line_date' => $reversal->entry_date,
                    'debit' => $originalLine->credit,
                    'credit' => $originalLine->debit,
                ]);
            }

            $invoice->update(['status' => 'cancelled']);

            return $invoice->fresh(['lines', 'payments']);
        });
    }

    public function recordPayment(SalesInvoice $invoice, string $amount, string $paymentDate, string $paymentMethod, int $userId): SalesInvoicePayment
    {
        if ($invoice->status !== 'confirmed') {
            throw new InvalidInvoiceStateException("Фактура #{$invoice->id} не е потврдена; плаќања можат да се внесуваат само за потврдени фактури.");
        }

        $invoice->loadMissing(['lines', 'payments', 'company']);

        if (bccomp($amount, $invoice->balanceDue(), 2) > 0) {
            throw new InvalidInvoiceStateException("Плаќањето од {$amount} го надминува преостанатото салдо од {$invoice->balanceDue()}.");
        }

        return DB::transaction(function () use ($invoice, $amount, $paymentDate, $paymentMethod, $userId) {
            $payment = $invoice->payments()->create([
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'payment_method' => $paymentMethod,
                'created_by' => $userId,
            ]);

            // Записот за плаќање останува во валутата на фактурата — салдото,
            // статусот и „За доплата“ се сметаат таму. Во главната книга оди
            // денарскиот износ.
            $amountMkd = $this->toMkd($invoice, $amount);

            $cashOrBankCode = $paymentMethod === 'cash' ? '102' : '100';
            $label = "Payment for invoice {$invoice->formattedNumber()}";

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,
                'entry_date' => $paymentDate,
                'description' => $label,
                'created_by' => $userId,
            ]);

            $entry->lines()->create(array_merge([
                'account_id' => $this->account($invoice->company, $cashOrBankCode)->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'line_date' => $paymentDate,
                'debit' => $amountMkd,
                'credit' => '0',
            ], $this->currencyColumns($invoice, $amount)));

            $entry->lines()->create(array_merge([
                'account_id' => $this->account($invoice->company, '120')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'line_date' => $paymentDate,
                'debit' => '0',
                'credit' => $amountMkd,
            ], $this->currencyColumns($invoice, $amount)));

            return $payment;
        });
    }

    /**
     * Износ од валутата на фактурата во денари.
     *
     * Книгите во Македонија се во денари, па девизната фактура се книжи по
     * курсот запишан на неа. Денарска фактура поминува недопрена — курсот е 1
     * и множењето не смее да помести ниту една пара од веќе книжените записи.
     *
     * Курсни разлики не се пресметуваат: наплатата се книжи по истиот курс, за
     * да се затвори побарувањето точно на нула.
     */
    private function toMkd(SalesInvoice $invoice, string $amount): string
    {
        if (! $invoice->isForeignCurrency()) {
            return $amount;
        }

        return Bcmath::roundHalfUp(bcmul($amount, (string) $invoice->exchange_rate, 10), 2);
    }

    /**
     * Девизните колони на една ставка од книжењето.
     *
     * `journal_entry_lines` веќе ги носи `currency_code`, `exchange_rate` и
     * `foreign_amount`, и формата за рачно книжење веќе ги полни — фактурата го
     * користи истиот образец, за да не се изгуби оригиналниот износ зад
     * денарскиот.
     *
     * Кај денарска фактура враќа празна низа: трите колони остануваат на своите
     * стандардни вредности и записот е буквално идентичен со досегашниот.
     */
    private function currencyColumns(SalesInvoice $invoice, string $foreignAmount): array
    {
        if (! $invoice->isForeignCurrency()) {
            return [];
        }

        return [
            'currency_code' => $invoice->currency,
            'exchange_rate' => (string) $invoice->exchange_rate,
            'foreign_amount' => $foreignAmount,
        ];
    }

    private function account(Company $company, string $code): Account
    {
        return Account::where('company_id', $company->id)->where('code', $code)->firstOrFail();
    }

    private function systemJournalGroup(Company $company): JournalGroup
    {
        return JournalGroup::firstOrCreate(
            ['company_id' => $company->id, 'code' => '99'],
            ['name' => 'Автоматски (фактури)', 'sort_order' => 99]
        );
    }
}
