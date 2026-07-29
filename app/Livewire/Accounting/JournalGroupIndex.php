<?php

namespace App\Livewire\Accounting;

use App\Models\Company;
use App\Models\JournalGroup;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class JournalGroupIndex extends Component
{
    public Company $company;

    public string $newCode = '';

    public string $newName = '';

    public ?int $editingGroupId = null;

    public string $editName = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function addGroup(): void
    {
        Gate::authorize('create', JournalGroup::class);

        $validated = $this->validate([
            'newCode' => ['required', 'string', 'digits:2', Rule::unique('journal_groups', 'code')->where('company_id', $this->company->id)],
            'newName' => ['required', 'string', 'max:255'],
        ]);

        JournalGroup::create([
            'company_id' => $this->company->id,
            'code' => $validated['newCode'],
            'name' => $validated['newName'],
            'sort_order' => (JournalGroup::where('company_id', $this->company->id)->max('sort_order') ?? 0) + 1,
        ]);

        $this->reset(['newCode', 'newName']);
    }

    public function startEditingGroup(int $groupId, string $currentName): void
    {
        $group = JournalGroup::where('company_id', $this->company->id)->findOrFail($groupId);
        Gate::authorize('update', $group);

        $this->editingGroupId = $groupId;
        $this->editName = $currentName;
    }

    public function cancelEditingGroup(): void
    {
        $this->editingGroupId = null;
        $this->editName = '';
    }

    public function updateGroupName(int $groupId): void
    {
        $group = JournalGroup::where('company_id', $this->company->id)->findOrFail($groupId);
        Gate::authorize('update', $group);

        $validated = $this->validate(['editName' => 'required|string|max:255']);

        $group->update(['name' => $validated['editName']]);

        $this->editingGroupId = null;
        $this->editName = '';
    }

    public function deleteGroup(int $groupId): void
    {
        $group = JournalGroup::where('company_id', $this->company->id)->findOrFail($groupId);
        Gate::authorize('delete', $group);

        if ($group->journalEntries()->exists()) {
            $this->addError('delete', 'Овој журнал веќе има внесени налози и не може да се избрише.');

            return;
        }

        $group->delete();
    }

    public function render()
    {
        return view('livewire.accounting.journal-group-index', [
            'groups' => JournalGroup::where('company_id', $this->company->id)->orderBy('sort_order')->orderBy('code')->get(),
        ]);
    }
}
