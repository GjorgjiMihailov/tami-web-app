<?php

namespace App\Livewire\Accounting;

use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class JournalEntryIndex extends Component
{
    use WithPagination;

    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        $entries = JournalEntry::where('company_id', $this->company->id)
            ->with('creator')
            ->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->paginate(25);

        return view('livewire.accounting.journal-entry-index', ['entries' => $entries]);
    }
}
