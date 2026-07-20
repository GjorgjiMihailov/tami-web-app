<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PartnerIndex extends Component
{
    public Company $company;

    public string $newName = '';

    public string $newTaxId = '';

    public string $newEmail = '';

    public string $newPhone = '';

    public string $newAddress = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function addPartner(): void
    {
        Gate::authorize('create', Partner::class);

        $validated = $this->validate([
            'newName' => 'required|string|max:255',
            'newTaxId' => 'nullable|string|max:255',
            'newEmail' => 'nullable|email|max:255',
            'newPhone' => 'nullable|string|max:255',
            'newAddress' => 'nullable|string|max:255',
        ]);

        Partner::create([
            'company_id' => $this->company->id,
            'name' => $validated['newName'],
            'tax_id' => $validated['newTaxId'] ?: null,
            'email' => $validated['newEmail'] ?: null,
            'phone' => $validated['newPhone'] ?: null,
            'address' => $validated['newAddress'] ?: null,
        ]);

        $this->reset(['newName', 'newTaxId', 'newEmail', 'newPhone', 'newAddress']);
    }

    public function render()
    {
        return view('livewire.partner-index', [
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
        ]);
    }
}
