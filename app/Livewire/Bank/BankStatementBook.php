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
use App\Services\Bank\BankStatementPoster;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\StatementControls;
use App\Support\Bcmath;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
            'id' => $line->id,
            'line_date' => $line->line_date->toDateString(),
            'direction' => $line->direction->value,
            'amount' => (string) $line->amount,
            'partner_id' => $line->partner_id,
            'description' => (string) $line->description,
            'kind' => $line->kind->value,
            'account_id' => $line->account_id,
            'invoice_id' => $line->sales_invoice_id ?? $line->purchase_invoice_id,
            'existing_payment_id' => $line->sales_invoice_payment_id ?? $line->purchase_invoice_payment_id,
        ])->all();
    }

    public function addLine(): void
    {
        if ($this->statement->isBooked()) {
            return;
        }

        $last = $this->lines === [] ? null : end($this->lines);

        $this->lines[] = [
            'id' => null,
            'line_date' => $last['line_date'] ?? $this->statement->statement_date->toDateString(),
            'direction' => LineDirection::IN->value,
            'amount' => '',
            'partner_id' => null,
            'description' => '',
            'kind' => LineKind::ACCOUNT->value,
            'account_id' => null,
            'invoice_id' => null,
            'existing_payment_id' => null,
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
            ])
            ->values()
            ->all();
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

    public function updated(string $name, mixed $value): void
    {
        // Избор на фактура: износот се нуди сам кога е празен, а старата врска
        // со постоечко плаќање се тргнува.
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
            'lines.*.direction' => ['required', 'in:in,out'],
            'lines.*.amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.kind' => ['required', 'in:invoice_payment,account,unclear'],
        ], [
            'openingBalance.regex' => 'Почетната состојба е број (пример 1000.50 или -200).',
            'closingBalance.regex' => 'Крајната состојба е број (пример 1000.50 или -200).',
            'lines.*.amount.required' => 'Внесете износ на секоја ставка.',
            'lines.*.amount.regex' => 'Износот е број со најмногу две децимали.',
        ]);

        DB::transaction(function () {
            $this->statement->update([
                'opening_balance' => $this->openingBalance === '' ? null : $this->openingBalance,
                'closing_balance' => $this->closingBalance === '' ? null : $this->closingBalance,
            ]);

            $this->statement->lines()->delete();

            foreach (array_values($this->lines) as $position => $line) {
                $isIn = $line['direction'] === LineDirection::IN->value;
                $isInvoice = $line['kind'] === LineKind::INVOICE_PAYMENT->value;

                $this->statement->lines()->create([
                    'position' => $position + 1,
                    'line_date' => $line['line_date'],
                    'direction' => $line['direction'],
                    'amount' => $line['amount'],
                    'partner_id' => $line['partner_id'] ?: null,
                    'description' => $line['description'] ?: null,
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

        return true;
    }

    public function render()
    {
        return view('livewire.bank.bank-statement-book', [
            'accounts' => Account::where('company_id', $this->company->id)->analytical()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(['id', 'name']),
            'problems' => $this->statement->isBooked() ? [] : StatementControls::problems($this->statement->fresh('lines')),
        ]);
    }
}
