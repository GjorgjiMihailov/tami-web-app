<?php

namespace App\Livewire\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Services\ExchangeRateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class JournalEntryForm extends Component
{
    public Company $company;

    public ?JournalEntry $journalEntry = null;

    public string $journalGroupId = '';

    public string $entryDate = '';

    public string $description = '';

    public array $lines = [];

    public bool $isForeignCurrency = false;

    protected ?string $previousEntryDate = null;

    public function mount(Company $company, ?JournalEntry $journalEntry = null): void
    {
        $this->company = $company;

        Gate::authorize($journalEntry ? 'view' : 'create', $journalEntry ?? JournalEntry::class);

        if ($journalEntry && $journalEntry->company_id !== $company->id) {
            abort(404);
        }

        $this->journalEntry = $journalEntry;

        if ($journalEntry) {
            $this->journalGroupId = (string) $journalEntry->journal_group_id;
            $this->entryDate = $journalEntry->entry_date->toDateString();
            $this->description = (string) $journalEntry->description;
            $this->lines = $journalEntry->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'partner_id' => $line->partner_id,
                'description' => $line->description,
                'line_date' => ($line->line_date ?? $journalEntry->entry_date)->toDateString(),
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'currency_code' => $line->currency_code,
                'exchange_rate' => (string) $line->exchange_rate,
                'foreign_amount' => $line->foreign_amount === null ? null : (string) $line->foreign_amount,
                '_key' => (string) Str::uuid(),
            ])->toArray();

            $this->isForeignCurrency = collect($this->lines)->contains(fn ($line) => $line['currency_code'] !== 'MKD');
        } else {
            $this->entryDate = now()->toDateString();
            $this->lines = [$this->emptyLine(), $this->emptyLine()];
        }
    }

    public function updatingEntryDate(): void
    {
        $this->previousEntryDate = $this->entryDate;
    }

    // Lines whose date still matches the entry date (i.e. never manually
    // overridden by the user) track a changed entry date; lines the user has
    // deliberately dated differently are left alone.
    public function updatedEntryDate(string $value): void
    {
        foreach ($this->lines as $index => $line) {
            if (($line['line_date'] ?? null) === $this->previousEntryDate) {
                $this->lines[$index]['line_date'] = $value;
            }
        }
    }

    protected function emptyLine(): array
    {
        return [
            'account_id' => '',
            'partner_id' => '',
            'description' => '',
            'line_date' => $this->entryDate,
            'debit' => '0',
            'credit' => '0',
            'currency_code' => 'MKD',
            'exchange_rate' => '1',
            'foreign_amount' => null,
            '_key' => (string) Str::uuid(),
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function fetchRate(int $index): void
    {
        $currency = $this->lines[$index]['currency_code'];
        $foreignAmount = (float) $this->lines[$index]['foreign_amount'];

        $rate = app(ExchangeRateService::class)->getRate($currency, Carbon::parse($this->entryDate));

        $this->lines[$index]['exchange_rate'] = (string) $rate;

        $mkdAmount = number_format($foreignAmount * $rate, 2, '.', '');

        // Defaults to filling debit — the common case — unless the user has
        // already put a value in credit (e.g. for a payment/liability line).
        if ((float) $this->lines[$index]['credit'] > 0) {
            $this->lines[$index]['credit'] = $mkdAmount;
        } else {
            $this->lines[$index]['debit'] = $mkdAmount;
        }
    }

    public function save(): void
    {
        Gate::authorize($this->journalEntry ? 'update' : 'create', $this->journalEntry ?? JournalEntry::class);

        // Normalize an empty-string partner_id (the default "no partner selected"
        // value) to null so the nullable exists() rule below treats it as absent
        // rather than trying to look up '' as a partner id.
        foreach ($this->lines as $index => $line) {
            if (($line['partner_id'] ?? null) === '') {
                $this->lines[$index]['partner_id'] = null;
            }
        }

        $this->validate([
            'entryDate' => 'required|date',
            'journalGroupId' => [$this->journalEntry ? 'nullable' : 'required', Rule::exists('journal_groups', 'id')->where('company_id', $this->company->id)],
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => ['required', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'lines.*.partner_id' => ['nullable', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
            'lines.*.line_date' => 'required|date',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
        ]);

        // Server-side fallback: if a foreign-currency line was set up (currency
        // + foreign_amount) but the user never clicked "NBRM" to fetch the rate
        // and fill debit/credit, compute the MKD value now so it can never be
        // silently posted as zero.
        foreach ($this->lines as $index => $line) {
            $currency = $line['currency_code'] ?? 'MKD';
            $foreignAmount = (float) ($line['foreign_amount'] ?? 0);

            if ($currency !== 'MKD' && $foreignAmount !== 0.0
                && (float) $line['debit'] === 0.0 && (float) $line['credit'] === 0.0) {
                $rate = app(ExchangeRateService::class)->getRate($currency, Carbon::parse($this->entryDate));
                $mkdAmount = number_format($foreignAmount * $rate, 2, '.', '');

                $this->lines[$index]['exchange_rate'] = (string) $rate;
                $this->lines[$index]['debit'] = $mkdAmount;
            }
        }

        foreach ($this->lines as $line) {
            if ((float) $line['debit'] > 0 && (float) $line['credit'] > 0) {
                $this->addError('lines', 'Ставката не може да има истовремено износ за должи и побарува — внесете само едно.');

                return;
            }
        }

        $totalDebit = collect($this->lines)->sum(fn ($line) => (float) $line['debit']);
        $totalCredit = collect($this->lines)->sum(fn ($line) => (float) $line['credit']);

        if (bccomp((string) $totalDebit, (string) $totalCredit, 2) !== 0) {
            $this->addError('lines', 'Налогот не е балансиран — вкупното должи мора да е еднакво на вкупното побарува.');

            return;
        }

        DB::transaction(function () {
            $entry = $this->journalEntry ?? new JournalEntry([
                'company_id' => $this->company->id,
                'created_by' => auth()->id(),
            ]);
            $entry->entry_date = $this->entryDate;
            $entry->description = $this->description;
            $entry->company_id = $this->company->id;

            if (! $entry->exists) {
                $entry->created_by = auth()->id();
                $entry->journal_group_id = $this->journalGroupId;
            }

            $entry->save();
            $entry->lines()->delete();

            foreach ($this->lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'partner_id' => $line['partner_id'] ?: null,
                    'description' => $line['description'],
                    'line_date' => $line['line_date'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'currency_code' => $line['currency_code'],
                    'exchange_rate' => $line['exchange_rate'],
                    'foreign_amount' => $line['foreign_amount'] ?: null,
                ]);
            }

            $this->journalEntry = $entry;
        });

        $this->redirect(route('accounting.journal-entries.index', $this->company));
    }

    public function delete(): void
    {
        if (! $this->journalEntry) {
            return;
        }

        Gate::authorize('delete', $this->journalEntry);

        if (SalesInvoice::where('journal_entry_id', $this->journalEntry->id)->exists()
            || PurchaseInvoice::where('journal_entry_id', $this->journalEntry->id)->exists()) {
            $this->addError('delete', 'Овој налог е автоматски создаден од фактура и не може да се избрише директно — прво откажете ја фактурата.');

            return;
        }

        $company = $this->company;

        // Documents are a polymorphic relation with no FK constraint, so
        // they would otherwise survive as orphaned rows pointing at a
        // deleted entry.
        $this->journalEntry->documents()->delete();
        $this->journalEntry->delete();

        $this->redirect(route('accounting.journal-entries.index', $company));
    }

    public function goToFirst(): void
    {
        $this->navigateTo($this->firstEntry());
    }

    public function goToPrevious(): void
    {
        $this->navigateTo($this->previousEntry());
    }

    public function goToNext(): void
    {
        $this->navigateTo($this->nextEntry());
    }

    public function goToLast(): void
    {
        $this->navigateTo($this->lastEntry());
    }

    private function firstEntry(): ?JournalEntry
    {
        if (! $this->journalEntry) {
            return null;
        }

        return JournalEntry::where('company_id', $this->company->id)
            ->where('journal_group_id', $this->journalEntry->journal_group_id)
            ->orderBy('fiscal_year')->orderBy('entry_number')
            ->first();
    }

    private function lastEntry(): ?JournalEntry
    {
        if (! $this->journalEntry) {
            return null;
        }

        return JournalEntry::where('company_id', $this->company->id)
            ->where('journal_group_id', $this->journalEntry->journal_group_id)
            ->orderByDesc('fiscal_year')->orderByDesc('entry_number')
            ->first();
    }

    private function previousEntry(): ?JournalEntry
    {
        if (! $this->journalEntry) {
            return null;
        }

        return JournalEntry::where('company_id', $this->company->id)
            ->where('journal_group_id', $this->journalEntry->journal_group_id)
            ->where(fn ($q) => $q->where('fiscal_year', '<', $this->journalEntry->fiscal_year)
                ->orWhere(fn ($q) => $q->where('fiscal_year', $this->journalEntry->fiscal_year)->where('entry_number', '<', $this->journalEntry->entry_number)))
            ->orderByDesc('fiscal_year')->orderByDesc('entry_number')
            ->first();
    }

    private function nextEntry(): ?JournalEntry
    {
        if (! $this->journalEntry) {
            return null;
        }

        return JournalEntry::where('company_id', $this->company->id)
            ->where('journal_group_id', $this->journalEntry->journal_group_id)
            ->where(fn ($q) => $q->where('fiscal_year', '>', $this->journalEntry->fiscal_year)
                ->orWhere(fn ($q) => $q->where('fiscal_year', $this->journalEntry->fiscal_year)->where('entry_number', '>', $this->journalEntry->entry_number)))
            ->orderBy('fiscal_year')->orderBy('entry_number')
            ->first();
    }

    private function navigateTo(?JournalEntry $target): void
    {
        if ($target) {
            $this->redirect(route('accounting.journal-entries.edit', [$this->company, $target]));
        }
    }

    public function render()
    {
        $accounts = Account::where('company_id', $this->company->id)->where('is_active', true)->orderBy('code')->get();
        $partners = Partner::where('company_id', $this->company->id)->orderBy('name')->get();

        return view('livewire.accounting.journal-entry-form', [
            'accounts' => $accounts,
            'partners' => $partners,
            'accountsForJs' => $accounts->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} — {$a->name}"])->values(),
            'partnersForJs' => $partners->map(fn ($p) => ['id' => $p->id, 'label' => $p->name])->values(),
            'groups' => JournalGroup::where('company_id', $this->company->id)->orderBy('sort_order')->orderBy('code')->get(),
            'hasPrevious' => $this->previousEntry() !== null,
            'hasNext' => $this->nextEntry() !== null,
            'totalDebit' => collect($this->lines)->sum(fn ($line) => (float) $line['debit']),
            'totalCredit' => collect($this->lines)->sum(fn ($line) => (float) $line['credit']),
        ]);
    }
}
