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
use App\Services\Posting\PostingSchemeEngine;
use App\Services\Posting\PostingSchemes;
use App\Services\Posting\SalesInvoicePostingContext;
use App\Support\Bcmath;
use App\Support\InvoiceNumber;
use App\Support\Posting\PostingDocType;
use Illuminate\Support\Facades\DB;

class SalesInvoiceService
{
    /**
     * Горен праг на чекорите при барање слободен број од серијата. Постои само
     * за да не се врти бесконечно ако нешто во податоците е наопаку —
     * нормалниот случај поминува од прв обид, а фирма со илјада скенирани
     * фактури по ред во иста година не постои.
     */
    private const MAX_NUMBER_ATTEMPTS = 1000;

    public function __construct(
        private StockMovementService $stockMovementService,
        private PostingSchemeEngine $postingEngine,
    ) {}

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

            // Фактура внесена од скен си го носи бројот од хартијата. Бројачот
            // на фирмата не смее да го потроши: ако земеше број од серијата, во
            // сопствената нумерација ќе останеше дупка за фактура што никогаш не
            // била издадена на тој број.
            $paperNumber = $invoice->invoice_number_formatted;

            if (filled($paperNumber)) {
                $clash = SalesInvoice::where('company_id', $invoice->company_id)
                    ->whereKeyNot($invoice->id)
                    ->where('invoice_number_formatted', $paperNumber)
                    ->whereYear('invoice_date', $fiscalYear)
                    ->lockForUpdate()
                    ->exists();

                if ($clash) {
                    throw new InvalidInvoiceStateException("Во {$fiscalYear} веќе постои фактура со број {$paperNumber}.");
                }

                $invoiceNumber = null;
                $formattedNumber = $paperNumber;
            } else {
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
                $formattedNumber = InvoiceNumber::format($invoice->company, $fiscalYear, $invoiceNumber);

                // Скенирана фактура носи `invoice_number = null`, па бројачот
                // погоре воопшто не ја гледа. Фирма што прво ги внела своите
                // хартиени фактури „2026/1".."2026/6" преку скен, а потоа
                // издава прва фактура низ Тами, добива токму „2026/1" — број
                // што веќе постои. Единствениот индекс тогаш пука, а секој нов
                // обид го дава истиот број. Затоа зафатениот испишан број се
                // прескокнува, а `invoice_number` останува на бројката што
                // навистина е употребена, за серијата да продолжи оттаму.
                $attempts = 0;

                while ($this->formattedNumberTaken($invoice, $fiscalYear, $formattedNumber)) {
                    if (++$attempts > self::MAX_NUMBER_ATTEMPTS) {
                        throw new InvalidInvoiceStateException("Не најдов слободен број за {$fiscalYear} — провери ги испишаните броеви на фактурите.");
                    }

                    $invoiceNumber++;
                    $formattedNumber = InvoiceNumber::format($invoice->company, $fiscalYear, $invoiceNumber);
                }
            }

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

            $label = 'Invoice '.$formattedNumber;
            $context = SalesInvoicePostingContext::build($invoice, $formattedNumber, $cogsTotal);
            $lines = $this->postingEngine->lines(PostingSchemes::for($invoice->company, PostingDocType::SALES_INVOICE), $context);

            $entry = JournalEntry::create([
                'company_id' => $invoice->company_id,
                'journal_group_id' => $this->systemJournalGroup($invoice->company)->id,
                'entry_date' => $invoice->invoice_date,
                'description' => "Sales {$label}",
                'created_by' => $userId,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create($line->journalColumns($invoice->invoice_date));
            }

            $invoice->update([
                'fiscal_year' => $fiscalYear,
                'invoice_number' => $invoiceNumber,
                'invoice_number_formatted' => $formattedNumber,
                'journal_entry_id' => $entry->id,
                'status' => 'confirmed',
            ]);

            return $invoice->fresh(['lines', 'payments']);
        });
    }

    /**
     * Целосно бришење на фактура — нацрт или потврдена. Потврдена фактура прво
     * го сторнира книжењето и залихата (истата логика што порано ја правеше
     * „Откажи"), но записот potoa целосно се брише, не остава „откажана"
     * фактура зад себе. Сопственикот изречно го избра ова однесување.
     */
    public function delete(SalesInvoice $invoice, int $userId): void
    {
        if (! in_array($invoice->status, ['draft', 'confirmed'], true)) {
            throw new InvalidInvoiceStateException("Фактура #{$invoice->id} не може да се избрише од оваа состојба.");
        }

        if ($invoice->isEfakturaLocked()) {
            throw new InvalidInvoiceStateException('Фактура испратена и прифатена преку е-Фактура не може да се брише.');
        }

        if ($invoice->payments()->exists()) {
            throw new InvalidInvoiceStateException('Фактура со евидентирани плаќања не може да се избрише.');
        }

        $invoice->loadMissing(['lines.item', 'lines.stockMovement', 'journalEntry', 'warehouse']);

        DB::transaction(function () use ($invoice, $userId) {
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

            // Прво се брише фактурата (таа покажува кон книжењето со journal_entry_id,
            // не обратно), па дури потоа книжењето — инаку странскиот клуч пука.
            $journalEntry = $invoice->journalEntry;
            $invoice->delete();
            $journalEntry?->delete();
        });
    }

    /**
     * Го менува испечатениот број на фактура (нацрт или потврдена), освен ако
     * е испратена и прифатена преку е-Фактура. На потврдена фактура го
     * ажурира и текстот во веќе книжените ставки (истиот стар број) за
     * главната книга да остане усогласена со новиот број.
     */
    public function changeNumber(SalesInvoice $invoice, string $newFormattedNumber, int $userId): SalesInvoice
    {
        if (! in_array($invoice->status, ['draft', 'confirmed'], true)) {
            throw new InvalidInvoiceStateException("Фактура #{$invoice->id} не може да го смени бројот од оваа состојба.");
        }

        if ($invoice->isEfakturaLocked()) {
            throw new InvalidInvoiceStateException('Фактура испратена и прифатена преку е-Фактура не може да го смени бројот.');
        }

        $newFormattedNumber = trim($newFormattedNumber);

        if ($newFormattedNumber === '') {
            throw new InvalidInvoiceStateException('Бројот не може да биде празен.');
        }

        $query = SalesInvoice::where('company_id', $invoice->company_id)
            ->whereKeyNot($invoice->id)
            ->where('invoice_number_formatted', $newFormattedNumber);

        $query = $invoice->status === 'confirmed'
            ? $query->where('fiscal_year', $invoice->fiscal_year)
            : $query->whereYear('invoice_date', $invoice->invoice_date->year);

        if ($query->exists()) {
            throw new InvalidInvoiceStateException("Веќе постои фактура со број {$newFormattedNumber} во таа година.");
        }

        return DB::transaction(function () use ($invoice, $newFormattedNumber) {
            $oldFormattedNumber = $invoice->invoice_number_formatted;
            $invoice->update(['invoice_number_formatted' => $newFormattedNumber]);

            if ($invoice->journal_entry_id !== null && filled($oldFormattedNumber)) {
                $invoice->loadMissing('journalEntry.lines');
                $oldLabel = 'Invoice '.$oldFormattedNumber;
                $newLabel = 'Invoice '.$newFormattedNumber;

                $invoice->journalEntry->update([
                    'description' => str_replace($oldLabel, $newLabel, $invoice->journalEntry->description),
                ]);

                foreach ($invoice->journalEntry->lines as $line) {
                    $line->update(['description' => str_replace($oldLabel, $newLabel, $line->description)]);
                }
            }

            return $invoice->fresh();
        });
    }

    /** Заедничка проверка: само потврдена фактура и не повеќе од салдото. */
    private function assertPayable(SalesInvoice $invoice, string $amount): void
    {
        if ($invoice->status !== 'confirmed') {
            throw new InvalidInvoiceStateException("Фактура #{$invoice->id} не е потврдена; плаќања можат да се внесуваат само за потврдени фактури.");
        }

        $invoice->loadMissing(['lines', 'payments', 'company']);

        if (bccomp($amount, $invoice->balanceDue(), 2) > 0) {
            throw new InvalidInvoiceStateException("Плаќањето од {$amount} го надминува преостанатото салдо од {$invoice->balanceDue()}.");
        }
    }

    /**
     * Само редот за плаќање, без налог: налогот го пишува изводот (една ставка
     * од изводот е една страна од неговиот налог). Само денарски фактури —
     * девизна фактура платена од денарски извод бара курсна логика што овде
     * намерно ја нема.
     */
    public function createPaymentRecord(SalesInvoice $invoice, string $amount, string $paymentDate, int $userId): SalesInvoicePayment
    {
        $amount = Bcmath::roundHalfUp($amount, 2);

        $this->assertPayable($invoice, $amount);

        if ($invoice->isForeignCurrency()) {
            throw new InvalidInvoiceStateException('Девизна фактура не може да се плати од денарски извод во оваа верзија.');
        }

        return $invoice->payments()->create([
            'amount' => $amount,
            'payment_date' => $paymentDate,
            'payment_method' => 'bank',
            'created_by' => $userId,
        ]);
    }

    public function recordPayment(SalesInvoice $invoice, string $amount, string $paymentDate, string $paymentMethod, int $userId): SalesInvoicePayment
    {
        // Нормализирано на 2 децимали пред каква било пресметка — записот за
        // плаќање (decimal(15,2)) заокружува, а bcadd подолу отсекува; ако не
        // се изедначат овде, книжењето и складираниот износ на плаќањето
        // тргнуваат по различен пат и остава остаток на 120. Валидацијата на
        // UI веќе го спречува ова, но сервисот мора да е точен без разлика на
        // повикувачот. За денарска фактура влезот е веќе на 2 децимали, па
        // ова не менува ништо.
        $amount = Bcmath::roundHalfUp($amount, 2);

        $this->assertPayable($invoice, $amount);

        return DB::transaction(function () use ($invoice, $amount, $paymentDate, $paymentMethod, $userId) {
            // Пресметано ПРЕД да се создаде овој запис за плаќање — ова е
            // состојбата на платено пред уплатата што штотуку ја книжиме.
            $paidBefore = $invoice->paidTotal();

            $payment = $invoice->payments()->create([
                'amount' => $amount,
                'payment_date' => $paymentDate,
                'payment_method' => $paymentMethod,
                'created_by' => $userId,
            ]);

            // Записот за плаќање останува во валутата на фактурата — салдото,
            // статусот и „За доплата“ се сметаат таму. Во главната книга оди
            // денарскиот износ.
            //
            // Секое плаќање книжи РАЗЛИКА меѓу конвертираната кумулативна сума
            // платена до сега и конвертираната сума платена претходно
            // (телескопирање). Заокружувањето секогаш паѓа на последното
            // плаќање, така што збирот на сите плаќања се затвора точно на
            // конвертираниот вкупен износ на фактурата — без разлика колку
            // пати е поделено плаќањето. Одделно заокружување на секое
            // плаќање наместо ова остава стотинка вишок или малку на 120.
            $paidAfter = bcadd($paidBefore, $amount, 2);
            $amountMkd = bcsub($this->toMkd($invoice, $paidAfter), $this->toMkd($invoice, $paidBefore), 2);

            $cashOrBankCode = $paymentMethod === 'cash' ? '102' : Account::BANK_CODE;
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

    /**
     * Дали испишаниот број е веќе зафатен во истата фирма и иста фискална
     * година. Опсегот е буквално оној на единствениот индекс
     * `sales_invoices_company_year_formatted_unique` — проверката има смисла
     * само ако гледа точно она што базата го брани.
     */
    private function formattedNumberTaken(SalesInvoice $invoice, int $fiscalYear, string $formattedNumber): bool
    {
        return SalesInvoice::where('company_id', $invoice->company_id)
            ->whereKeyNot($invoice->id)
            ->where('fiscal_year', $fiscalYear)
            ->where('invoice_number_formatted', $formattedNumber)
            ->exists();
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
