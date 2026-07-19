<?php

namespace App\Livewire\Accounting;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Services\ExchangeRateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class JournalEntryForm extends Component
{
    public Company $company;

    public ?JournalEntry $journalEntry = null;

    public string $entryDate = '';

    public string $description = '';

    public array $lines = [];

    public function mount(Company $company, ?JournalEntry $journalEntry = null): void
    {
        $this->company = $company;
        $this->journalEntry = $journalEntry;

        Gate::authorize($journalEntry ? 'update' : 'create', $journalEntry ?? JournalEntry::class);

        if ($journalEntry) {
            $this->entryDate = $journalEntry->entry_date->toDateString();
            $this->description = (string) $journalEntry->description;
            $this->lines = $journalEntry->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'partner_id' => $line->partner_id,
                'description' => $line->description,
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'currency_code' => $line->currency_code,
                'exchange_rate' => (string) $line->exchange_rate,
                'foreign_amount' => $line->foreign_amount === null ? null : (string) $line->foreign_amount,
            ])->toArray();
        } else {
            $this->entryDate = now()->toDateString();
            $this->lines = [$this->emptyLine(), $this->emptyLine()];
        }
    }

    protected function emptyLine(): array
    {
        return [
            'account_id' => '',
            'partner_id' => '',
            'description' => '',
            'debit' => '0',
            'credit' => '0',
            'currency_code' => 'MKD',
            'exchange_rate' => '1',
            'foreign_amount' => null,
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

        $this->validate([
            'entryDate' => 'required|date',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:accounts,id',
        ]);

        $totalDebit = collect($this->lines)->sum(fn ($line) => (float) $line['debit']);
        $totalCredit = collect($this->lines)->sum(fn ($line) => (float) $line['credit']);

        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            $this->addError('lines', 'The entry does not balance — total debit must equal total credit.');

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
            }

            $entry->save();
            $entry->lines()->delete();

            foreach ($this->lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'partner_id' => $line['partner_id'] ?: null,
                    'description' => $line['description'],
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

    public function render()
    {
        return view('livewire.accounting.journal-entry-form', [
            'accounts' => Account::where('company_id', $this->company->id)->where('is_active', true)->orderBy('code')->get(),
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
