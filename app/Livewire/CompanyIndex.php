<?php

namespace App\Livewire;

use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CompanyIndex extends Component
{
    public string $newName = '';

    public string $newTaxId = '';

    public string $newEmail = '';

    public string $newPhone = '';

    public string $newAddress = '';

    public ?int $editingCompanyId = null;

    public string $editBankAccount = '';

    public bool $editIsVatRegistered = true;

    public function addCompany(): void
    {
        Gate::authorize('create', Company::class);

        $validated = $this->validate([
            'newName' => 'required|string|max:255',
            'newTaxId' => 'nullable|string|max:255',
            'newEmail' => 'nullable|email|max:255',
            'newPhone' => 'nullable|string|max:255',
            'newAddress' => 'nullable|string|max:255',
        ]);

        Company::create([
            'name' => $validated['newName'],
            'tax_id' => $validated['newTaxId'] ?: null,
            'email' => $validated['newEmail'] ?: null,
            'phone' => $validated['newPhone'] ?: null,
            'address' => $validated['newAddress'] ?: null,
        ]);

        $this->reset(['newName', 'newTaxId', 'newEmail', 'newPhone', 'newAddress']);
    }

    public function startEdit(int $companyId): void
    {
        $company = Company::findOrFail($companyId);
        Gate::authorize('update', $company);

        $this->editingCompanyId = $company->id;
        $this->editBankAccount = (string) $company->bank_account;
        $this->editIsVatRegistered = $company->is_vat_registered;
    }

    public function saveEdit(): void
    {
        $company = Company::findOrFail($this->editingCompanyId);
        Gate::authorize('update', $company);

        $validated = $this->validate([
            'editBankAccount' => 'nullable|string|max:255',
            'editIsVatRegistered' => 'boolean',
        ]);

        $company->update([
            'bank_account' => $validated['editBankAccount'] ?: null,
            'is_vat_registered' => $validated['editIsVatRegistered'],
        ]);

        $this->editingCompanyId = null;
    }

    public function render()
    {
        $companies = auth()->user()->visibleCompanies()->orderBy('name')->get();

        return view('livewire.company-index', ['companies' => $companies]);
    }
}
