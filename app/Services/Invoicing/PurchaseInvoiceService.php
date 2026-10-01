<?php

namespace App\Services\Invoicing;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseInvoicePayment;
use App\Services\Inventory\LandedCostAllocator;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    public function __construct(private StockMovementService $stockMovementService) {}

    public function confirm(PurchaseInvoice $invoice, int $userId): PurchaseInvoice
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidInvoiceStateException("Влезна фактура #{$invoice->id} не е нацрт и не може да се потврди.");
        }

        $invoice->loadMissing(['lines.item', 'company']);

        if ($invoice->lines->isEmpty()) {
            throw new InvalidInvoiceStateException('Влезната фактура мора да содржи барем една ставка пред да се потврди.');
        }

        $hasStockLines = $invoice->lines->contains(fn ($line) => $this->movesStock($line));

        if ($hasStockLines && $invoice->warehouse_id === null) {
            throw new InvalidInvoiceStateException('Потребен е магацин за потврдување влезна фактура со ставки со артикли.');
        }

        foreach ($invoice->lines as $index => $line) {
            $position = $index + 1;

            if ($this->movesStock($line) && $line->vat_deductible === false) {
                throw new InvalidInvoiceStateException("ДДВ без право на одбивка не е поддржано за ставки со артикл од залиха (ставка на позиција {$position}).");
            }

            if (! $this->movesStock($line) && $line->account_id === null) {
                throw new InvalidInvoiceStateException("Ставка што не е артикл од залиха мора да содржи сметка за трошок (ставка на позиција {$position}).");
            }
        }

        return DB::transaction(function () use ($invoice, $userId) {
            $invoice->loadMissing(['lines.account', 'lines.item', 'partner', 'company', 'importCosts', 'tariffLines']);

            $vatRegistered = $invoice->company->is_vat_registered;
            $vatTotal = '0.00';
            $debitsByAccountId = [];
            $landedCosts = [];

            if ($invoice->is_import) {
                $this->guardLandedCostAllocatable($invoice);
                $landedCosts = $this->landedCosts($invoice);
            }

            foreach ($invoice->lines as $line) {
                $lineNet = $line->lineTotal();
                $lineVat = $vatRegistered ? $line->vatAmount() : '0.00';
                $deductible = $vatRegistered && $line->vat_deductible;

                if ($this->movesStock($line)) {
                    $movement = $this->stockMovementService->receipt(
                        $line->item,
                        $invoice->warehouse,
                        (string) $line->quantity,
                        // На увозна фактура, залихата прима landed (магацинска)
                        // цена наместо чистата фактурна — распределени увозни
                        // трошоци/царина. Книжењето подолу останува на
                        // фактурниот износ, намерно (видете ја спецификацијата).
                        //
                        // ВАЖНО за усогласување на сметка 660: ова НЕ е пропуст
                        // во кодот. Штом ваквата (landed) залиха подоцна се
                        // ПРОДАДЕ, постоечкото книжење на трошок на продадени
                        // производи (Должи 701 / Побарува 660) ја зема
                        // просечната landed цена — повисока од она со што 660
                        // е задолжена при набавка. Сметка 660 останува
                        // усогласена само ако канцеларијата ЗАСЕБНО ја книжи
                        // фактурата на шпедитерот/царина како обична влезна
                        // фактура со нејзината ставка насочена кон сметка 660
                        // (залиха), не кон трошковна сметка — работна
                        // инструкција за канцеларијата, не код (свесна одлука
                        // на сопственикот; видете ја спецификацијата).
                        //
                        // Не `unit_price`: кај ставка внесена со бруто цена
                        // заокружената нето цена веќе не ја дава основицата,
                        // па залихата би примила 30,48 таму каде главната
                        // книга задолжува 30,51.
                        $landedCosts[$line->id] ?? $line->effectiveUnitPrice(),
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
                'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,
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
                    'line_date' => $invoice->invoice_date,
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
                    'line_date' => $invoice->invoice_date,
                    'debit' => $vatTotal,
                    'credit' => '0',
                ]);
                $grossTotal = bcadd($grossTotal, $vatTotal, 2);
            }

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, '220')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'line_date' => $invoice->invoice_date,
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

    /**
     * Целосно бришење на влезна фактура — нацрт или потврдена. Потврдена
     * прво ја сторнира залихата (истата логика што порано ја правеше
     * „Откажи"), но записот потоа целосно се брише наместо да остане
     * „откажана" фактура. Сопственикот изречно го избра ова однесување.
     */
    public function delete(PurchaseInvoice $invoice, int $userId): void
    {
        if (! in_array($invoice->status, ['draft', 'confirmed'], true)) {
            throw new InvalidInvoiceStateException("Влезна фактура #{$invoice->id} не може да се избрише од оваа состојба.");
        }

        if ($invoice->payments()->exists()) {
            throw new InvalidInvoiceStateException('Влезна фактура со евидентирани плаќања не може да се избрише.');
        }

        $invoice->loadMissing(['lines.item', 'lines.stockMovement', 'journalEntry', 'warehouse']);

        DB::transaction(function () use ($invoice, $userId) {
            foreach ($invoice->lines as $line) {
                if (! $this->movesStock($line) || $line->stockMovement === null) {
                    continue;
                }

                try {
                    $this->stockMovementService->issue(
                        $line->item,
                        $invoice->warehouse,
                        (string) $line->quantity,
                        now()->toDateString(),
                        $userId
                    );
                } catch (InsufficientStockException $e) {
                    throw new InvalidInvoiceStateException(
                        "Не може да се избрише влезна фактура #{$invoice->id}: примената стока веќе е искористена на друго место ({$e->getMessage()})."
                    );
                }
            }

            // Прво се брише фактурата (таа покажува кон книжењето со journal_entry_id,
            // не обратно), па дури потоа книжењето — инаку странскиот клуч пука.
            $journalEntry = $invoice->journalEntry;
            $invoice->delete();
            $journalEntry?->delete();
        });
    }

    /**
     * Го менува бројот на фактурата од добавувачот (нацрт или потврдена).
     * За влезна фактура нема законска серија (тоа е туѓ, не наш број), па нема
     * е-Фактура-заклучување — влезните е-Фактури се одделен запис
     * (IncomingEfakturaDocument), не оваа фактура.
     */
    public function changeSupplierNumber(PurchaseInvoice $invoice, string $newNumber, int $userId): PurchaseInvoice
    {
        if (! in_array($invoice->status, ['draft', 'confirmed'], true)) {
            throw new InvalidInvoiceStateException("Влезна фактура #{$invoice->id} не може да го смени бројот од оваа состојба.");
        }

        $newNumber = trim($newNumber);

        if ($newNumber === '') {
            throw new InvalidInvoiceStateException('Бројот не може да биде празен.');
        }

        return DB::transaction(function () use ($invoice, $newNumber) {
            $invoice->loadMissing('partner');
            $oldNumber = $invoice->supplier_invoice_number;
            $invoice->update(['supplier_invoice_number' => $newNumber]);

            if ($invoice->journal_entry_id !== null && filled($oldNumber)) {
                $invoice->loadMissing('journalEntry.lines');
                $oldRef = "{$invoice->partner->name} #{$oldNumber}";
                $newRef = "{$invoice->partner->name} #{$newNumber}";

                $invoice->journalEntry->update([
                    'description' => str_replace($oldRef, $newRef, $invoice->journalEntry->description),
                ]);

                foreach ($invoice->journalEntry->lines as $line) {
                    $line->update(['description' => str_replace($oldRef, $newRef, $line->description)]);
                }
            }

            return $invoice->fresh();
        });
    }

    public function recordPayment(PurchaseInvoice $invoice, string $amount, string $paymentDate, string $paymentMethod, int $userId): PurchaseInvoicePayment
    {
        if ($invoice->status !== 'confirmed') {
            throw new InvalidInvoiceStateException("Влезна фактура #{$invoice->id} не е потврдена; плаќања можат да се внесуваат само за потврдени фактури.");
        }

        $invoice->loadMissing(['lines', 'payments', 'company', 'partner']);

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

            $cashOrBankCode = $paymentMethod === 'cash' ? '102' : '100';
            $label = "Payment for purchase bill {$invoice->partner->name} #{$invoice->supplier_invoice_number}";

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,
                'entry_date' => $paymentDate,
                'description' => $label,
                'created_by' => $userId,
            ]);

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, '220')->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'line_date' => $paymentDate,
                'debit' => $amount,
                'credit' => '0',
            ]);

            $entry->lines()->create([
                'account_id' => $this->account($invoice->company, $cashOrBankCode)->id,
                'partner_id' => $invoice->partner_id,
                'description' => $label,
                'line_date' => $paymentDate,
                'debit' => '0',
                'credit' => $amount,
            ]);

            return $payment;
        });
    }

    /**
     * Only a stocked product moves inventory. A service item is a cost like any
     * other — it carries an item for the record, but it books to the expense
     * account the user picked on the line and never touches a warehouse.
     */
    private function movesStock(PurchaseInvoiceLine $line): bool
    {
        return $line->item_id !== null && ! $line->item->isService();
    }

    /**
     * @return array<int, string> purchase_invoice_line id => landed unit cost
     */
    private function landedCosts(PurchaseInvoice $invoice): array
    {
        $stockLines = [];

        foreach ($invoice->lines as $line) {
            if ($this->movesStock($line)) {
                $stockLines[$line->id] = ['net' => $line->lineTotal(), 'quantity' => (string) $line->quantity];
            }
        }

        return app(LandedCostAllocator::class)->allocate($stockLines, $this->totalImportCost($invoice));
    }

    private function totalImportCost(PurchaseInvoice $invoice): string
    {
        $costsTotal = $invoice->importCosts->reduce(
            fn (?string $carry, $cost) => bcadd($carry ?? '0.00', (string) $cost->base_amount, 2),
            '0.00'
        );
        $dutyTotal = $invoice->tariffLines->reduce(
            fn (?string $carry, $tariff) => bcadd($carry ?? '0.00', (string) $tariff->customs_duty, 2),
            '0.00'
        );

        return bcadd($costsTotal, $dutyTotal, 2);
    }

    /**
     * LandedCostAllocator е чист калкулатор без состојба и намерно не фрла
     * исклучоци — само дели. Ако СИТЕ ставки-артикли на увозна фактура имаат
     * нето вредност нула (бесплатни примероци, гарантна замена), а сепак
     * постојат увозни трошоци/царина за распределба, поделбата
     * net/quantity тивко би дала 0 за секоја ставка — трошокот би исчезнал
     * без трага, без предупредување. За сметководствен систем тивко губење
     * пари е полошо од одбиена операција, па проверката е тука, пред
     * повикот кон калкулаторот, а не внатре во него.
     */
    private function guardLandedCostAllocatable(PurchaseInvoice $invoice): void
    {
        if (bccomp($this->totalImportCost($invoice), '0', 2) <= 0) {
            return;
        }

        $totalNet = '0.00';

        foreach ($invoice->lines as $line) {
            if ($this->movesStock($line)) {
                $totalNet = bcadd($totalNet, $line->lineTotal(), 2);
            }
        }

        if (bccomp($totalNet, '0', 2) <= 0) {
            throw new InvalidInvoiceStateException(
                'Не можат да се распределат увозни трошоци — ниту една ставка со артикл нема вредност поголема од нула.'
            );
        }
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
