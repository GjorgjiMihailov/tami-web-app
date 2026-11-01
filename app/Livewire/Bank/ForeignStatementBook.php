<?php

namespace App\Livewire\Bank;

use App\Exceptions\InvalidBankStatementException;
use App\Exceptions\InvalidInvoiceStateException;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Services\Bank\BankStatementPoster;
use App\Services\ExchangeRateService;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\StatementControls;
use App\Support\Bcmath;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Рачно внесување на ставките на еден девизен извод и негово книжење.
 *
 * Износите се во валутата на изводот. Два вида ставки: наплата на фактура
 * (уплата) и продажба на девиза (исплата), секоја со курс на денот.
 * Вистинската проверка е StatementControls::problems при потврда.
 */
#[Layout('layouts.app')]
class ForeignStatementBook extends Component
{
    public Company $company;

    public BankStatement $statement;

    public string $openingBalance = '';

    public string $closingBalance = '';

    /** @var array<int, array<string, mixed>> */
    public array $lines = [];

    public function mount(Company $company, BankStatement $statement): void
    {
        Gate::authorize('view', $company);
        Gate::authorize('create', JournalEntry::class);

        if ($statement->company_id !== $company->id || ! $statement->kind->isForeign()) {
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
            'kind' => $line->kind->value,
            'amount' => (string) $line->amount,
            'exchange_rate' => $line->exchange_rate === null ? '' : (string) $line->exchange_rate,
            'partner_id' => $line->partner_id,
            'invoice_id' => $line->sales_invoice_id,
            'existing_payment_id' => $line->sales_invoice_payment_id,
            'description' => (string) $line->description,
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
            'kind' => LineKind::INVOICE_PAYMENT->value,
            'amount' => '',
            'exchange_rate' => '',
            'partner_id' => null,
            'invoice_id' => null,
            'existing_payment_id' => null,
            'description' => '',
        ];
    }

    public function removeLine(int $index): void
    {
        if ($this->statement->isBooked()) {
            return;
        }

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /** Курс на НБРМ за валутата на изводот на датумот на ставката. */
    public function fetchRate(int $index): void
    {
        if ($this->statement->isBooked() || ! isset($this->lines[$index])) {
            return;
        }

        $this->resetErrorBag("lines.$index.exchange_rate");

        try {
            $rate = app(ExchangeRateService::class)->getRate($this->statement->currency, Carbon::parse($this->lines[$index]['line_date']));
        } catch (\Throwable) {
            $this->addError("lines.$index.exchange_rate", 'Курсот не се презеде од НБРМ — впиши го рачно.');

            return;
        }

        $this->lines[$index]['exchange_rate'] = (string) $rate;
    }

    /**
     * Отворени потврдени фактури на партнерот во валутата на изводот.
     *
     * @return array<int, array{id: int, label: string, balance: string}>
     */
    public function invoiceOptions(int $index): array
    {
        $line = $this->lines[$index] ?? null;

        if ($line === null || empty($line['partner_id']) || $line['kind'] !== LineKind::INVOICE_PAYMENT->value) {
            return [];
        }

        return SalesInvoice::where('company_id', $this->company->id)
            ->where('partner_id', $line['partner_id'])
            ->where('currency', $this->statement->currency)
            ->where('status', 'confirmed')
            ->with(['lines', 'payments'])
            ->get()
            ->filter(fn (SalesInvoice $invoice) => bccomp($invoice->balanceDue(), '0', 2) > 0)
            ->map(fn (SalesInvoice $invoice) => [
                'id' => $invoice->id,
                'label' => $invoice->formattedNumber().' — салдо '.Format::money($invoice->balanceDue(), ''),
                'balance' => $invoice->balanceDue(),
            ])
            ->values()
            ->all();
    }

    /**
     * Веќе внесени банкарски наплати на избраната фактура (копчето „Наплата“) што не се врзани
     * за друга ставка на извод — кандидати за „веќе книжено“.
     *
     * @return array<int, array{id: int, label: string}>
     */
    public function paymentOptions(int $index): array
    {
        $line = $this->lines[$index] ?? null;

        if ($line === null || empty($line['invoice_id']) || ! is_numeric($line['invoice_id'])) {
            return [];
        }

        $invoice = SalesInvoice::where('company_id', $this->company->id)->find($line['invoice_id']);

        if ($invoice === null) {
            return [];
        }

        $taken = BankStatementLine::whereNotNull('sales_invoice_payment_id')
            ->when(! empty($line['id']), fn ($query) => $query->whereKeyNot($line['id']))
            ->pluck('sales_invoice_payment_id');

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

    /** @return array{in: string, out: string} */
    #[Computed]
    public function totals(): array
    {
        $in = '0.00';
        $out = '0.00';

        foreach ($this->lines as $line) {
            if (! Bcmath::isPlainNumber((string) $line['amount'])) {
                continue;
            }

            if ($line['kind'] === LineKind::CURRENCY_SALE->value) {
                $out = bcadd($out, (string) $line['amount'], 2);
            } else {
                $in = bcadd($in, (string) $line['amount'], 2);
            }
        }

        return ['in' => $in, 'out' => $out];
    }

    /** Разлика во живо: почетна + движење − крајна, во валутата на изводот. */
    #[Computed]
    public function difference(): ?string
    {
        $signed = collect($this->lines)
            ->filter(fn (array $line) => Bcmath::isPlainNumber((string) $line['amount']))
            ->map(fn (array $line) => $line['kind'] === LineKind::CURRENCY_SALE->value
                ? bcmul((string) $line['amount'], '-1', 2)
                : bcadd((string) $line['amount'], '0', 2));

        $opening = Bcmath::isPlainNumber($this->openingBalance) ? bcadd($this->openingBalance, '0', 2) : null;
        $closing = Bcmath::isPlainNumber($this->closingBalance) ? bcadd($this->closingBalance, '0', 2) : null;

        return StatementControls::difference($opening, $closing, $signed);
    }

    public function updated(string $name, mixed $value): void
    {
        // Друг вид или друг партнер ја менува смислата на избраната фактура.
        if (preg_match('/^lines\.(\d+)\.(kind|partner_id)$/', $name, $m)) {
            $this->lines[(int) $m[1]]['invoice_id'] = null;
            $this->lines[(int) $m[1]]['existing_payment_id'] = null;

            if ($m[2] === 'kind' && $value === LineKind::CURRENCY_SALE->value) {
                $this->lines[(int) $m[1]]['partner_id'] = null;
            }
        }

        // Избор на фактура: износот се нуди сам кога е празен.
        if (preg_match('/^lines\.(\d+)\.invoice_id$/', $name, $m) && $value) {
            $index = (int) $m[1];
            $this->lines[$index]['existing_payment_id'] = null;

            foreach ($this->invoiceOptions($index) as $option) {
                if ($option['id'] === (int) $value && $this->lines[$index]['amount'] === '') {
                    $this->lines[$index]['amount'] = $option['balance'];
                }
            }
        }
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
            'lines.*.kind' => ['required', 'in:invoice_payment,currency_sale'],
            'lines.*.amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.exchange_rate' => ['nullable', 'regex:/^\d{1,6}(\.\d{1,6})?$/'],
            'lines.*.invoice_id' => ['nullable', 'integer'],
            'lines.*.existing_payment_id' => ['nullable', 'integer'],
            'lines.*.partner_id' => ['nullable', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
        ], [
            'lines.*.partner_id.exists' => 'Партнерот не е од оваа фирма.',
            'openingBalance.regex' => 'Почетната состојба е број (пример 1000.50 или -200).',
            'closingBalance.regex' => 'Крајната состојба е број (пример 1000.50 или -200).',
            'lines.*.amount.required' => 'Внесете износ на секоја ставка.',
            'lines.*.amount.regex' => 'Износот е број со најмногу две децимали.',
            'lines.*.exchange_rate.regex' => 'Курсот е број до шест цифри пред точката и најмногу шест децимали.',
            'lines.*.invoice_id.integer' => 'Изберете фактура од листата.',
        ]);

        DB::transaction(function () {
            $previous = $this->statement->lines()->get()->keyBy('id');

            // Id што го нема значи дека изводот е сменет во друг прозорец.
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
                $isSale = $line['kind'] === LineKind::CURRENCY_SALE->value;

                $this->statement->lines()->create([
                    'position' => $position + 1,
                    'line_date' => $line['line_date'],
                    'direction' => $isSale ? LineDirection::OUT->value : LineDirection::IN->value,
                    'amount' => $line['amount'],
                    'currency' => $this->statement->currency,
                    'exchange_rate' => ($line['exchange_rate'] ?? '') === '' ? null : $line['exchange_rate'],
                    'partner_id' => $isSale ? null : ($line['partner_id'] ?: null),
                    'description' => $line['description'] ?: null,
                    'kind' => $line['kind'],
                    'sales_invoice_id' => $isSale ? null : ($line['invoice_id'] ?: null),
                    'sales_invoice_payment_id' => $isSale ? null : ($line['existing_payment_id'] ?: null),
                    'reviewed_at' => now(),
                ]);
            }
        });

        $this->statement = $this->statement->fresh();

        foreach ($this->statement->lines as $position => $saved) {
            $this->lines[$position]['id'] = $saved->id;
        }

        return true;
    }

    public function render()
    {
        $partners = Partner::where('company_id', $this->company->id)->orderBy('name')->get(['id', 'name']);

        return view('livewire.bank.foreign-statement-book', [
            'partners' => $partners,
            'problems' => $this->statement->isBooked() ? [] : StatementControls::problems($this->statement->fresh('lines')),
        ]);
    }
}
