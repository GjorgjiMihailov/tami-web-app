<?php

namespace App\Livewire\Accounting;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AccountIndex extends Component
{
    public Company $company;

    public string $newCode = '';

    public string $newName = '';

    public string $newParentCode = '';

    public string $search = '';

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
            'newCode' => ['required', 'string', 'max:10', 'regex:/^[0-9]{4,}$/', Rule::unique('accounts', 'code')->where('company_id', $this->company->id)],
            'newName' => 'required|string|max:255',
            'newParentCode' => [
                'required', 'string', 'max:9',
                Rule::exists('accounts', 'code')->where('company_id', $this->company->id),
            ],
        ]);

        if (! str_starts_with($validated['newCode'], $validated['newParentCode'])
            || strlen($validated['newCode']) <= strlen($validated['newParentCode'])) {
            $this->addError('newCode', 'Шифрата мора да почнува со шифрата на родителот и да е подолга од неа.');

            return;
        }

        Account::create([
            'company_id' => $this->company->id,
            'code' => $validated['newCode'],
            'name' => $validated['newName'],
            'parent_code' => $validated['newParentCode'],
            'level' => Account::LEVEL_ACCOUNT,
            'is_analytical' => true,
            'is_active' => true,
        ]);

        $this->reset(['newCode', 'newName', 'newParentCode']);
    }

    public function render()
    {
        $search = trim($this->search);

        $accountsByClass = Account::where('company_id', $this->company->id)
            ->when($search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('code', 'like', $search.'%')->orWhere('name', 'like', '%'.$search.'%')
            ))
            ->orderBy('code')
            ->get()
            ->groupBy('class');

        // The policy asks the database per account, and every account on this
        // page belongs to the same company — ask once instead of ~2000 times.
        $sample = $accountsByClass->flatten()->first();

        return view('livewire.accounting.account-index', [
            'accountsByClass' => $accountsByClass,
            'canUpdate' => $sample !== null && Gate::allows('update', $sample),
        ]);
    }
}
