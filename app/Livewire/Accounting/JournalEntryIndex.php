<?php

namespace App\Livewire\Accounting;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class JournalEntryIndex extends Component
{
    use InteractsWithWorkingYear;
    use WithPagination;

    public Company $company;

    // Optional: set when the user drilled in from Главна книга, null when
    // they opened the flat "all entries this year" list. Both entry points
    // stay valid so no existing link or bookmark breaks.
    public ?JournalGroup $journalGroup = null;

    public function mount(Company $company, ?JournalGroup $journalGroup = null): void
    {
        Gate::authorize('view', $company);

        if ($journalGroup && $journalGroup->company_id !== $company->id) {
            abort(404);
        }

        $this->company = $company;
        $this->journalGroup = $journalGroup;
        $this->workingYear = WorkingYear::for($company);
    }

    public function render()
    {
        // fiscal_year is derived from entry_date on create and is never
        // rewritten, so filtering on it is exact — see JournalEntry::booted().
        $entries = JournalEntry::where('company_id', $this->company->id)
            ->where('fiscal_year', $this->workingYear)
            ->when($this->journalGroup, fn ($q) => $q->where('journal_group_id', $this->journalGroup->id))
            ->with(['creator', 'journalGroup'])
            ->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->paginate(25);

        return view('livewire.accounting.journal-entry-index', ['entries' => $entries]);
    }
}
