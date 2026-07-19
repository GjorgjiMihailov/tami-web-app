<?php

namespace App\Livewire\Accounting;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AccountIndex extends Component
{
    public Company $company;

    public string $newCode = '';

    public string $newName = '';

    public string $newParentCode = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function toggleActive(int $accountId): void
    {
        $account = Account::where('company_id', $this->company->id)->findOrFail($accountId);
        Gate::authorize('update', $account);

        $account->update(['is_active' => ! $account->is_active]);
    }

    public function addAnalyticalAccount(): void
    {
        Gate::authorize('create', Account::class);

        $validated = $this->validate([
            'newCode' => 'required|string|max:10|regex:/^[0-9]{4,}$/',
            'newName' => 'required|string|max:255',
            'newParentCode' => 'required|string|size:3',
        ]);

        Account::create([
            'company_id' => $this->company->id,
            'code' => $validated['newCode'],
            'name' => $validated['newName'],
            'parent_code' => $validated['newParentCode'],
            'is_analytical' => true,
            'is_active' => true,
        ]);

        $this->reset(['newCode', 'newName', 'newParentCode']);
    }

    public function render()
    {
        $accountsByClass = Account::where('company_id', $this->company->id)
            ->orderBy('code')
            ->get()
            ->groupBy('class');

        return view('livewire.accounting.account-index', ['accountsByClass' => $accountsByClass]);
    }
}
