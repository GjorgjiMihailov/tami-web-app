<?php

namespace App\Livewire\Bank;

use App\Exceptions\InvalidBankStatementException;
use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Account;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Services\Bank\BankCounterpartyRecognizer;
use App\Services\Bank\BankStatementPoster;
use App\Services\Posting\PostedInvoiceAccounts;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\MatchConfidence;
use App\Support\Bank\StatementControls;
use App\Support\Bcmath;
use App\Support\Format;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Рачно внесување на ставките на еден денарски извод и негово книжење.
 *
 * Состојбите се од наша гледна точка: „Побарува“ на изводот е плус, „Долгува“ е
 * минус. Лентата за разлика се пресметува во живо од тоа што е на екранот;
 * вистинската проверка е StatementControls::problems при потврда.
 */
#[Layout('layouts.app')]
class BankStatementBook extends Component
{
    public Company $company;

    public BankStatement $statement;

    public string $openingBalance = '';

    public string $closingBalance = '';

    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    /** Режим „Проверка една по една“ е отворен. */
    public bool $reviewing = false;

    /** Индексот во `$lines` на ставката што е на ред за проверка. */
    public ?int $reviewIndex = null;

    /** Порака по делење на уплата на повеќе фактури. */
    public ?string $splitNotice = null;

    public function mount(Company $company, BankStatement $statement): void
    {
        Gate::authorize('view', $company);
        Gate::authorize('create', JournalEntry::class);

        if ($statement->company_id !== $company->id || ! $statement->kind->isDenar()) {
            abort(404);
        }

        $this->company = $company;
        $this->statement = $statement;
        $this->openingBalance = $statement->opening_balance === null ? '' : (string) $statement->opening_balance;
        $this->closingBalance = $statement->closing_balance === null ? '' : (string) $statement->closing_balance;
        $this->lines = $statement->lines->map(fn (BankStatementLine $line) => [
            '_key' => (string) Str::uuid(),
            'id' => $line->id,
            'line_date' => $line->line_date->toDateString(),
            'direction' => $line->direction->value,
            'amount' => (string) $line->amount,
            // Изводот е од гледна точка на банката: исплата = Должи, уплата = Побарува.
            'debit' => $line->direction === LineDirection::OUT ? (string) $line->amount : '',
            'credit' => $line->direction === LineDirection::IN ? (string) $line->amount : '',
            'partner_id' => $line->partner_id,
            'description' => (string) $line->description,
            'kind' => $line->kind->value,
            'account_id' => $line->account_id,
            'invoice_id' => $line->sales_invoice_id ?? $line->purchase_invoice_id,
            'existing_payment_id' => $line->sales_invoice_payment_id ?? $line->purchase_invoice_payment_id,
            'reviewed' => $line->reviewed_at !== null,
        ])->all();
    }

    public function addLine(): void
    {
        if ($this->statement->isBooked()) {
            return;
        }

        $last = $this->lines === [] ? null : end($this->lines);

        $this->lines[] = [
            '_key' => (string) Str::uuid(),
            'id' => null,
            'line_date' => $last['line_date'] ?? $this->statement->statement_date->toDateString(),
            'direction' => LineDirection::IN->value,
            'amount' => '',
            'debit' => '',
            'credit' => '',
            'partner_id' => null,
            'description' => '',
            'kind' => LineKind::ACCOUNT->value,
            'account_id' => null,
            'invoice_id' => null,
            'existing_payment_id' => null,
            'reviewed' => true,
        ];
    }

    public function removeLine(int $index): void
    {
        if ($this->statement->isBooked()) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);

        // Индексите се поместени: отворената проверка би покажала друга ставка.
        $this->stopReview();
    }

    /**
     * Вкупно Должи (исплати) и Побарува (уплати) од тоа што е на екранот.
     *
     * @return array{debit: string, credit: string}
     */
    #[Computed]
    public function totals(): array
    {
        $debit = '0.00';
        $credit = '0.00';

        foreach ($this->lines as $line) {
            if (! Bcmath::isPlainNumber((string) $line['amount'])) {
                continue;
            }

            if ($line['direction'] === LineDirection::OUT->value) {
                $debit = bcadd($debit, (string) $line['amount'], 2);
            } else {
                $credit = bcadd($credit, (string) $line['amount'], 2);
            }
        }

        return ['debit' => $debit, 'credit' => $credit];
    }

    /** Разлика во живо: почетна + движење − крајна. */
    #[Computed]
    public function difference(): ?string
    {
        $signed = collect($this->lines)
            ->filter(fn (array $line) => Bcmath::isPlainNumber((string) $line['amount']))
            ->map(fn (array $line) => $line['direction'] === LineDirection::OUT->value
                ? bcmul((string) $line['amount'], '-1', 2)
                : bcadd((string) $line['amount'], '0', 2));

        $opening = Bcmath::isPlainNumber($this->openingBalance) ? bcadd($this->openingBalance, '0', 2) : null;
        $closing = Bcmath::isPlainNumber($this->closingBalance) ? bcadd($this->closingBalance, '0', 2) : null;

        return StatementControls::difference($opening, $closing, $signed);
    }

    /**
     * Отворени потврдени фактури на партнерот на ставката: излезни за уплата,
     * влезни за исплата. Од излезните само денарски.
     *
     * @return array<int, array{id: int, label: string, balance: string}>
     */
    public function invoiceOptions(int $index): array
    {
        $line = $this->lines[$index] ?? null;

        if ($line === null || empty($line['partner_id'])) {
            return [];
        }

        $isIn = $line['direction'] === LineDirection::IN->value;
        $query = $isIn ? SalesInvoice::where('currency', 'MKD') : PurchaseInvoice::query();

        return $query->where('company_id', $this->company->id)
            ->where('partner_id', $line['partner_id'])
            ->where('status', 'confirmed')
            ->with(['lines', 'payments'])
            ->get()
            ->filter(fn ($invoice) => bccomp($invoice->balanceDue(), '0', 2) > 0)
            ->map(fn ($invoice) => [
                'id' => $invoice->id,
                'label' => ($isIn ? $invoice->formattedNumber() : $invoice->supplier_invoice_number)
                    .' — салдо '.Format::money($invoice->balanceDue(), ''),
                'balance' => $invoice->balanceDue(),
                'date' => $invoice->invoice_date->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Една уплата (исплата) затвора повеќе фактури: ставката се дели на по една
     * ставка за фактура. Најстарите се затвораат прво, секоја до своето салдо, додека
     * не се потроши износот; ако остане, остатокот е ставка без фактура за да не се
     * наруши збирот на изводот. Секоја нова ставка е обична ставка — книжењето не знае
     * за делењето.
     *
     * @param  array<int, int|string>  $invoiceIds
     */
    public function splitAcrossInvoices(int $index, array $invoiceIds): void
    {
        if ($this->statement->isBooked() || ! isset($this->lines[$index])) {
            return;
        }

        $this->resetErrorBag("lines.$index.amount");
        $this->splitNotice = null;

        $line = $this->lines[$index];
        $amount = (string) $line['amount'];

        if (! Bcmath::isPlainNumber($amount) || bccomp($amount, '0', 2) <= 0) {
            $this->addError("lines.$index.amount", 'Прво внесете го износот на уплатата.');

            return;
        }

        // Само отворени фактури на партнерот на ставката (од свежо пресметаните опции) —
        // ID-јата од прелистувачот не се доверливи.
        $wanted = array_map('intval', $invoiceIds);
        $options = collect($this->invoiceOptions($index))
            ->filter(fn (array $option) => in_array($option['id'], $wanted, true))
            ->sortBy([['date', 'asc'], ['id', 'asc']])
            ->values();

        if ($options->isEmpty()) {
            $this->addError("lines.$index.amount", 'Изберете барем една отворена фактура на партнерот.');

            return;
        }

        $remaining = bcadd($amount, '0', 2);
        $allocations = [];

        foreach ($options as $option) {
            if (bccomp($remaining, '0', 2) <= 0) {
                break;
            }

            $take = bccomp($option['balance'], $remaining, 2) < 0 ? bcadd($option['balance'], '0', 2) : $remaining;
            $allocations[] = ['invoice_id' => $option['id'], 'amount' => $take];
            $remaining = bcsub($remaining, $take, 2);
        }

        $notUsed = $options->count() - count($allocations);

        if (bccomp($remaining, '0', 2) > 0) {
            $allocations[] = ['invoice_id' => null, 'amount' => $remaining];
            $this->splitNotice = 'Остана '.Format::money($remaining, '').' без фактура — изберете конто или фактура за последната ставка.';
        } elseif ($notUsed > 0) {
            $this->splitNotice = 'Уплатата се потроши — '.$notUsed.' од избраните фактури не се затворени.';
        }

        $rows = [];

        foreach ($allocations as $position => $allocation) {
            $isInvoice = $allocation['invoice_id'] !== null;
            $base = $position === 0 ? $line : array_merge($line, ['_key' => (string) Str::uuid(), 'id' => null]);

            $rows[] = array_merge($base, [
                'amount' => $allocation['amount'],
                'kind' => $isInvoice ? LineKind::INVOICE_PAYMENT->value : LineKind::ACCOUNT->value,
                'invoice_id' => $allocation['invoice_id'],
                'account_id' => null,
                'existing_payment_id' => null,
                'reviewed' => true,
            ]);
        }

        array_splice($this->lines, $index, 1, $rows);

        foreach (array_keys($rows) as $offset) {
            $this->syncSides($index + $offset);
        }

        // Индексите се поместени: отворената проверка би покажала друга ставка.
        $this->stopReview();
    }

    /**
     * Веќе внесени банкарски плаќања на избраната фактура што не се врзани за
     * друга ставка на извод — кандидати за „веќе книжено“.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public function paymentOptions(int $index): array
    {
        $line = $this->lines[$index] ?? null;

        if ($line === null || empty($line['invoice_id'])) {
            return [];
        }

        $isIn = $line['direction'] === LineDirection::IN->value;
        $invoice = $isIn
            ? SalesInvoice::where('company_id', $this->company->id)->find($line['invoice_id'])
            : PurchaseInvoice::where('company_id', $this->company->id)->find($line['invoice_id']);

        if ($invoice === null) {
            return [];
        }

        $linkColumn = $isIn ? 'sales_invoice_payment_id' : 'purchase_invoice_payment_id';
        $taken = BankStatementLine::whereNotNull($linkColumn)
            ->when($line['id'] !== null, fn ($query) => $query->whereKeyNot($line['id']))
            ->pluck($linkColumn);

        return $invoice->payments()
            ->where('payment_method', 'bank')
            ->whereNotIn('id', $taken)
            ->orderBy('payment_date')
            ->get()
            ->map(fn ($payment) => [
                'id' => $payment->id,
                'label' => Format::date($payment->payment_date).' — '.Format::money((string) $payment->amount, ''),
            ])
            ->all();
    }

    /**
     * Сметката што ја затвора ставката со избрана фактура: онаму каде што
     * фактурата го отворила побарувањето/обврската (120/220 за денарски, 121/221
     * за девизни). Истата сметка ја користи книжењето — овде само се покажува.
     */
    public function closingAccount(int $index): ?Account
    {
        $line = $this->lines[$index] ?? null;

        if ($line === null || empty($line['invoice_id'])) {
            return null;
        }

        $isIn = $line['direction'] === LineDirection::IN->value;

        try {
            if ($isIn) {
                $invoice = SalesInvoice::where('company_id', $this->company->id)->find($line['invoice_id']);

                return $invoice === null ? null : PostedInvoiceAccounts::receivable($invoice);
            }

            $invoice = PurchaseInvoice::where('company_id', $this->company->id)->find($line['invoice_id']);

            return $invoice === null ? null : PostedInvoiceAccounts::payable($invoice);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    public function updated(string $name, mixed $value): void
    {
        // Внесот е во Должи/Побарува; насоката и износот се изведуваат од тоа.
        // Должи (исплата) и Побарува (уплата) се исклучуваат меѓусебно.
        if (preg_match('/^lines\.(\d+)\.(debit|credit)$/', $name, $m)) {
            $this->applySide((int) $m[1], $m[2] === 'debit');
        }

        // Насока или износ сменети на друго место (преглед една по една):
        // двете колони го следат тоа.
        if (preg_match('/^lines\.(\d+)\.(direction|amount)$/', $name, $m)) {
            $this->syncSides((int) $m[1]);
        }

        // Насока, партнер или вид ја менуваат смислата на избраната фактура
        // (излезна наспроти влезна), па изборот се чисти — инаку старото id
        // би покажало на погрешна фактура од другата страна.
        if (preg_match('/^lines\.(\d+)\.(direction|partner_id|kind)$/', $name, $m)) {
            $this->lines[(int) $m[1]]['invoice_id'] = null;
            $this->lines[(int) $m[1]]['existing_payment_id'] = null;
        }

        // Избор на фактура: износот се нуди сам кога е празен, а старата врска
        // со постоечко плаќање се тргнува.
        if (preg_match('/^lines\.(\d+)\.invoice_id$/', $name, $m) && $value) {
            $index = (int) $m[1];
            $this->lines[$index]['existing_payment_id'] = null;

            foreach ($this->invoiceOptions($index) as $option) {
                if ($option['id'] === (int) $value && $this->lines[$index]['amount'] === '') {
                    $this->lines[$index]['amount'] = $option['balance'];
                    $this->syncSides($index);
                }
            }
        }

        // Табелата нема посебен избор „Намена“: изборот на конто или на фактура
        // за затворање сам го одредува видот на ставката.
        if (preg_match('/^lines\.(\d+)\.account_id$/', $name, $m) && $value) {
            $this->lines[(int) $m[1]]['kind'] = LineKind::ACCOUNT->value;
        }

        if (preg_match('/^lines\.(\d+)\.invoice_id$/', $name, $m) && $value) {
            $this->lines[(int) $m[1]]['kind'] = LineKind::INVOICE_PAYMENT->value;
        }

        // Сметководителот сам ја одредил ставката (конто, фактура, партнер, вид):
        // тоа е ист преглед како „Потврди“. Во режимот на проверка тоа го прави
        // копчето „Потврди и следна“, не самото менување.
        if (! $this->reviewing && preg_match('/^lines\.(\d+)\.(kind|account_id|partner_id|invoice_id|existing_payment_id)$/', $name, $m)) {
            $this->lines[(int) $m[1]]['reviewed'] = true;
        }
    }

    private function applySide(int $index, bool $isDebit): void
    {
        if (! isset($this->lines[$index])) {
            return;
        }

        $mine = $isDebit ? 'debit' : 'credit';
        $other = $isDebit ? 'credit' : 'debit';
        $value = trim((string) ($this->lines[$index][$mine] ?? ''));
        $this->lines[$index][$mine] = $value;

        if ($value === '') {
            // Празна колона: износот се чисти само ако е празна и другата.
            if (($this->lines[$index][$other] ?? '') === '') {
                $this->lines[$index]['amount'] = '';
            }

            return;
        }

        $direction = $isDebit ? LineDirection::OUT->value : LineDirection::IN->value;

        // Насоката ја менува смислата на избраната фактура (излезна/влезна).
        if ($this->lines[$index]['direction'] !== $direction) {
            $this->lines[$index]['invoice_id'] = null;
            $this->lines[$index]['existing_payment_id'] = null;
        }

        $this->lines[$index]['direction'] = $direction;
        $this->lines[$index]['amount'] = $value;
        $this->lines[$index][$other] = '';
    }

    private function syncSides(int $index): void
    {
        if (! isset($this->lines[$index])) {
            return;
        }

        $amount = (string) ($this->lines[$index]['amount'] ?? '');
        $isOut = ($this->lines[$index]['direction'] ?? '') === LineDirection::OUT->value;

        $this->lines[$index]['debit'] = $isOut ? $amount : '';
        $this->lines[$index]['credit'] = $isOut ? '' : $amount;
    }

    public function save(): void
    {
        $this->persist();
    }

    public function post(BankStatementPoster $poster): void
    {
        Gate::authorize('create', JournalEntry::class);

        if (! $this->persist()) {
            return;
        }

        try {
            $poster->post($this->statement->fresh(), auth()->id());
        } catch (InvalidBankStatementException|InvalidInvoiceStateException $e) {
            $this->addError('post', $e->getMessage());

            return;
        }

        $this->statement = $this->statement->fresh();
    }

    public function reopen(BankStatementPoster $poster): void
    {
        Gate::authorize('create', JournalEntry::class);

        try {
            $poster->reopen($this->statement->fresh());
        } catch (InvalidBankStatementException $e) {
            $this->addError('post', $e->getMessage());

            return;
        }

        $this->statement = $this->statement->fresh();
    }

    /**
     * Ги потврдува сите предложени ставки одеднаш: означени „проверете“, веќе
     * со избрано конто или фактура. Сметководителот ги видел во табелата пред
     * да притисне; нејасните остануваат за проверка една по една.
     */
    public function confirmSuggested(): void
    {
        if ($this->statement->isBooked()) {
            return;
        }

        $indexes = $this->suggestedIndexes();

        if ($indexes === []) {
            return;
        }

        foreach ($indexes as $i) {
            $this->lines[$i]['reviewed'] = true;
        }

        $this->persist();
    }

    /** Ја отвора проверката на првата непотврдена ставка. */
    public function startReview(): void
    {
        if ($this->statement->isBooked()) {
            return;
        }

        $pending = $this->pendingIndexes();

        if ($pending === []) {
            $this->stopReview();

            return;
        }

        $this->resetErrorBag('review');
        $this->reviewing = true;
        $this->reviewIndex = $pending[0];
    }

    public function stopReview(): void
    {
        $this->reviewing = false;
        $this->reviewIndex = null;
        $this->resetErrorBag('review');
    }

    /** Ја потврдува тековната ставка (зачувува) и оди на следната непотврдена. */
    public function confirmAndNext(): void
    {
        $i = $this->currentReviewIndex();

        if ($i === null) {
            return;
        }

        $line = $this->lines[$i];

        $error = match (true) {
            $line['kind'] === LineKind::INVOICE_PAYMENT->value && empty($line['invoice_id']) => 'Изберете фактура.',
            $line['kind'] === LineKind::ACCOUNT->value && empty($line['account_id']) => 'Изберете конто.',
            $line['kind'] === LineKind::UNCLEAR->value => 'Изберете „Плаќање на фактура“ или „Конто“.',
            default => null,
        };

        if ($error !== null) {
            $this->addError('review', $error);

            return;
        }

        $this->resetErrorBag('review');
        $wasReviewed = ! empty($this->lines[$i]['reviewed']);
        $this->lines[$i]['reviewed'] = true;

        try {
            $saved = $this->persist();
        } catch (ValidationException $e) {
            // Се враќа каква што била: ставка потврдена порано не се „одпотврдува“.
            $this->lines[$i]['reviewed'] = $wasReviewed;

            throw $e;
        }

        if (! $saved) {
            return;
        }

        $pending = $this->pendingIndexes();
        $after = array_values(array_filter($pending, fn (int $n) => $n > $i));
        $target = $after[0] ?? ($pending[0] ?? null);

        if ($target === null) {
            $this->stopReview();

            return;
        }

        $this->reviewIndex = $target;
    }

    /** Следна ставка за преглед (потврдена или не), кружно. */
    public function skipLine(): void
    {
        $i = $this->currentReviewIndex();

        if ($i === null) {
            return;
        }

        $all = $this->reviewable();
        $after = array_values(array_filter($all, fn (int $n) => $n > $i));
        $this->reviewIndex = $after[0] ?? $all[0];
    }

    public function previousLine(): void
    {
        $i = $this->currentReviewIndex();

        if ($i === null) {
            return;
        }

        $before = array_values(array_filter($this->reviewable(), fn (int $n) => $n < $i));
        $this->reviewIndex = $before === [] ? $i : end($before);
    }

    /** Тековната ставка ако режимот е валиден; инаку го затвора. */
    private function currentReviewIndex(): ?int
    {
        $i = $this->reviewIndex;

        if ($this->reviewing && $i !== null && ! $this->statement->isBooked()
            && isset($this->lines[$i]) && in_array($i, $this->reviewable(), true)) {
            return $i;
        }

        $this->stopReview();

        return null;
    }

    /**
     * Индекси на ставки означени за преглед (не „сигурно“). Ознаката се чита од
     * базата по id, не од формата.
     *
     * @return list<int>
     */
    private function reviewable(): array
    {
        $saved = $this->statement->lines()->get()->keyBy('id');
        $indexes = [];

        foreach ($this->lines as $i => $line) {
            if ($saved->get($line['id'] ?? 0)?->match_confidence?->needsReview()) {
                $indexes[] = $i;
            }
        }

        return $indexes;
    }

    /**
     * Предложени за групно потврдување: „проверете“, непотврдени, со избрано
     * конто (вид конто) или фактура (плаќање на фактура).
     *
     * @return list<int>
     */
    private function suggestedIndexes(): array
    {
        $saved = $this->statement->lines()->get()->keyBy('id');
        $indexes = [];

        foreach ($this->lines as $i => $line) {
            if (! empty($line['reviewed'])) {
                continue;
            }

            $row = $saved->get($line['id'] ?? 0);

            // Само предлог од запомнетото, и само ако партнерот нема отворена
            // фактура во таа насока: тогаш може да е плаќање на фактура, а не на
            // запомнатото конто — и тоа го одлучува човек, не групното копче.
            if ($row?->match_confidence !== MatchConfidence::CHECK
                || $row->match_source !== BankCounterpartyRecognizer::SOURCE
                || $this->invoiceOptions($i) !== []) {
                continue;
            }

            $resolved = ($line['kind'] === LineKind::ACCOUNT->value && ! empty($line['account_id']))
                || ($line['kind'] === LineKind::INVOICE_PAYMENT->value && ! empty($line['invoice_id']));

            if ($resolved) {
                $indexes[] = $i;
            }
        }

        return $indexes;
    }

    /** @return list<int> означени за преглед, а сè уште непотврдени */
    private function pendingIndexes(): array
    {
        return array_values(array_filter($this->reviewable(), fn (int $i) => empty($this->lines[$i]['reviewed'])));
    }

    private function persist(): bool
    {
        Gate::authorize('create', JournalEntry::class);

        if ($this->statement->isBooked()) {
            return false;
        }

        $this->validate([
            'openingBalance' => ['nullable', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'closingBalance' => ['nullable', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'lines.*.line_date' => ['required', 'date'],
            'lines.*.direction' => ['required', 'in:in,out'],
            'lines.*.amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.kind' => ['required', 'in:invoice_payment,account,unclear'],
            'lines.*.partner_id' => ['nullable', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
        ], [
            'lines.*.partner_id.exists' => 'Партнерот не е од оваа фирма.',
            'openingBalance.regex' => 'Почетната состојба е број (пример 1000.50 или -200).',
            'closingBalance.regex' => 'Крајната состојба е број (пример 1000.50 или -200).',
            'lines.*.amount.required' => 'Внесете износ на секоја ставка.',
            'lines.*.amount.regex' => 'Износот е број со најмногу две децимали.',
        ]);

        DB::transaction(function () {
            // Ставките се бришат и создаваат одново. Податоците што дошле со
            // фајлот (другата страна, повикување на број, ознаки, потврди) ги
            // нема во формата, па се пренесуваат по id од досегашните ставки на
            // ОВОЈ извод — не од формата, чии вредности клиентот може да ги измени.
            $previous = $this->statement->lines()->get()->keyBy('id');

            // Id што го нема меѓу тековните ставки значи дека изводот е сменет
            // откако е отворен овој прозорец (друг таб го зачувал и ставките се
            // создадени одново) или дека id е изменет. Зачувување од стара копија
            // би ги избришало ознаките и потврдите и би ја заобиколило проверката.
            foreach ($this->lines as $line) {
                if (! empty($line['id']) && ! $previous->has((int) $line['id'])) {
                    throw ValidationException::withMessages([
                        'post' => 'Изводот е изменет во друг прозорец — освежете ја страницата.',
                    ]);
                }
            }

            $this->statement->update([
                'opening_balance' => $this->openingBalance === '' ? null : $this->openingBalance,
                'closing_balance' => $this->closingBalance === '' ? null : $this->closingBalance,
            ]);

            $this->statement->lines()->delete();

            foreach (array_values($this->lines) as $position => $line) {
                $isIn = $line['direction'] === LineDirection::IN->value;
                $isInvoice = $line['kind'] === LineKind::INVOICE_PAYMENT->value;
                $kept = $previous->get($line['id'] ?? 0);

                $this->statement->lines()->create([
                    'position' => $position + 1,
                    'line_date' => $line['line_date'],
                    'direction' => $line['direction'],
                    'amount' => $line['amount'],
                    'partner_id' => $line['partner_id'] ?: null,
                    'description' => $line['description'] ?: null,
                    'reference_number' => $kept?->reference_number,
                    'purpose_code' => $kept?->purpose_code,
                    'counterparty_account' => $kept?->counterparty_account,
                    'counterparty_name' => $kept?->counterparty_name,
                    'match_confidence' => $kept?->match_confidence,
                    'match_reason' => $kept?->match_reason,
                    'match_source' => $kept?->match_source,
                    'reviewed_at' => ! empty($line['reviewed']) ? ($kept?->reviewed_at ?? now()) : null,
                    'kind' => $line['kind'],
                    'account_id' => $isInvoice ? null : ($line['account_id'] ?: null),
                    'sales_invoice_id' => $isInvoice && $isIn ? ($line['invoice_id'] ?: null) : null,
                    'purchase_invoice_id' => $isInvoice && ! $isIn ? ($line['invoice_id'] ?: null) : null,
                    'sales_invoice_payment_id' => $isInvoice && $isIn ? ($line['existing_payment_id'] ?: null) : null,
                    'purchase_invoice_payment_id' => $isInvoice && ! $isIn ? ($line['existing_payment_id'] ?: null) : null,
                ]);
            }
        });

        $this->statement = $this->statement->fresh();

        // Ставките се создадени со нови id-ја; формата мора да ги знае, инаку
        // второто зачувување нема од каде да ги пренесе податоците.
        $this->lines = array_values($this->lines);

        foreach ($this->statement->lines as $position => $saved) {
            $this->lines[$position]['id'] = $saved->id;
        }

        return true;
    }

    public function render()
    {
        $accounts = Account::where('company_id', $this->company->id)->analytical()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);

        $partners = Partner::where('company_id', $this->company->id)->orderBy('name')->get(['id', 'name']);

        return view('livewire.bank.bank-statement-book', [
            'accounts' => $accounts,
            'partners' => $partners,
            'partnersForJs' => $partners->map(fn ($p) => ['id' => $p->id, 'label' => $p->name])->values(),
            'accountsForJs' => $accounts->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} — {$a->name}"])->values(),
            'problems' => $this->statement->isBooked() ? [] : StatementControls::problems($this->statement->fresh('lines')),
            'pendingCount' => $this->statement->isBooked() ? 0 : count($this->pendingIndexes()),
            'suggestedCount' => $this->statement->isBooked() ? 0 : count($this->suggestedIndexes()),
            'reviewable' => $this->reviewing ? $this->reviewable() : [],
            'lineMeta' => $this->statement->lines()->get()->keyBy('id'),
        ]);
    }
}
