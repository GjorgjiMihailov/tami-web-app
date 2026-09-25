<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PartnerForm extends Component
{
    public Company $company;

    public string $name = '';

    public string $type = 'legal_entity';

    public string $taxId = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        Gate::authorize('create', Partner::class);

        $this->company = $company;
    }

    public function save()
    {
        Gate::authorize('create', Partner::class);

        $this->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['individual', 'legal_entity'])],
            'taxId' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
        ]);

        $partner = Partner::create([
            'company_id' => $this->company->id,
            'name' => $this->name,
            'type' => $this->type,
            'tax_id' => $this->taxId ?: null,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
        ]);

        return $this->redirect(route('partners.show', [$this->company, $partner]), navigate: true);
    }

    public function render()
    {
        return view('livewire.partner-form');
    }
}
