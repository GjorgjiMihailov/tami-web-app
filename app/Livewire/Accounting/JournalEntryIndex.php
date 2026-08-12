<?php

namespace App\Livewire\Accounting;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Models\JournalEntry;
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

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
    }

    public function render()
    {
        // fiscal_year is derived from entry_date on create and is never
        // rewritten, so filtering on it is exact — see JournalEntry::booted().
        $entries = JournalEntry::where('company_id', $this->company->id)
            ->where('fiscal_year', $this->workingYear)
            ->with(['creator', 'journalGroup'])
            ->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->paginate(25);

        return view('livewire.accounting.journal-entry-index', ['entries' => $entries]);
    }
}
