<?php

namespace App\Livewire;

use App\Models\Company;
use App\Support\CompanyType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CompanyIndex extends Component
{
    public string $newName = '';

    public string $newType = '';

    public string $newTaxId = '';

    public string $newEmail = '';

    public string $newPhone = '';

    public string $newAddress = '';

    public function mount(): void
    {
        // Фирми is an admin screen — see the role table in
        // docs/superpowers/specs/2026-08-11-sidebar-ia-and-working-year-design.md.
        // An accountant with several companies reaches the chooser through
        // App\Livewire\Dashboard instead, which is not a menu entry.
        abort_unless(auth()->user()->hasRole('admin'), 403);
    }

    public function addCompany(): void
    {
        Gate::authorize('create', Company::class);

        $validated = $this->validate([
            'newName' => 'required|string|max:255',
            'newType' => ['required', Rule::enum(CompanyType::class)],
            'newTaxId' => 'nullable|string|max:255',
            'newEmail' => 'nullable|email|max:255',
            'newPhone' => 'nullable|string|max:255',
            'newAddress' => 'nullable|string|max:255',
        ]);

        Company::create([
            'name' => $validated['newName'],
            'type' => $validated['newType'],
            'tax_id' => $validated['newTaxId'] ?: null,
            'email' => $validated['newEmail'] ?: null,
            'phone' => $validated['newPhone'] ?: null,
            'address' => $validated['newAddress'] ?: null,
        ]);

        $this->reset(['newName', 'newType', 'newTaxId', 'newEmail', 'newPhone', 'newAddress']);
    }

    public function render()
    {
        $companies = auth()->user()->visibleCompanies()->orderBy('name')->get();

        return view('livewire.company-index', ['companies' => $companies]);
    }
}
