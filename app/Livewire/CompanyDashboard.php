<?php

namespace App\Livewire;

use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CompanyDashboard extends Component
{
    public Company $company;

    public bool $editing = false;

    public string $editName = '';

    public string $editShortName = '';

    public string $editTaxId = '';

    public string $editRegistrationNumber = '';

    public string $editNkdCode = '';

    public string $editNkdName = '';

    public string $editEmail = '';

    public string $editPhone = '';

    public string $editWebsite = '';

    public string $editAddress = '';

    public string $editDirectorName = '';

    public string $editDirectorPhone = '';

    public string $editDirectorEmail = '';

    public bool $editIsVatRegistered = true;

    public array $bankAccounts = [];

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function startEdit(): void
    {
        Gate::authorize('update', $this->company);

        $this->editName = $this->company->name;
        $this->editShortName = (string) $this->company->short_name;
        $this->editTaxId = (string) $this->company->tax_id;
        $this->editRegistrationNumber = (string) $this->company->registration_number;
        $this->editNkdCode = (string) $this->company->nkd_code;
        $this->editNkdName = (string) $this->company->nkd_name;
        $this->editEmail = (string) $this->company->email;
        $this->editPhone = (string) $this->company->phone;
        $this->editWebsite = (string) $this->company->website;
        $this->editAddress = (string) $this->company->address;
        $this->editDirectorName = (string) $this->company->director_name;
        $this->editDirectorPhone = (string) $this->company->director_phone;
        $this->editDirectorEmail = (string) $this->company->director_email;
        $this->editIsVatRegistered = $this->company->is_vat_registered;

        $existing = $this->company->bankAccounts()->get();
        $this->bankAccounts = $existing->isEmpty()
            ? [['bank_name' => '', 'account_number' => '']]
            : $existing->map(fn ($row) => [
                'bank_name' => (string) $row->bank_name,
                'account_number' => (string) $row->account_number,
            ])->all();

        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
    }

    public function updated(string $name, $value): void
    {
        if (! str_ends_with($name, '.account_number')) {
            return;
        }

        $lastIndex = array_key_last($this->bankAccounts);
        $currentIndex = (int) explode('.', $name)[1];

        if ($currentIndex === $lastIndex && trim((string) $value) !== '' && count($this->bankAccounts) < 5) {
            $this->bankAccounts[] = ['bank_name' => '', 'account_number' => ''];
        }
    }

    public function save(): void
    {
        Gate::authorize('update', $this->company);

        $validated = $this->validate([
            'editName' => 'required|string|max:255',
            'editShortName' => 'nullable|string|max:255',
            'editTaxId' => 'nullable|string|max:255',
            'editRegistrationNumber' => 'nullable|string|max:255',
            'editNkdCode' => 'nullable|string|max:255',
            'editNkdName' => 'nullable|string|max:255',
            'editEmail' => 'nullable|email|max:255',
            'editPhone' => 'nullable|string|max:255',
            'editWebsite' => 'nullable|string|max:255',
            'editAddress' => 'nullable|string|max:255',
            'editDirectorName' => 'nullable|string|max:255',
            'editDirectorPhone' => 'nullable|string|max:255',
            'editDirectorEmail' => 'nullable|email|max:255',
            'editIsVatRegistered' => 'boolean',
            'bankAccounts' => 'array|max:5',
            'bankAccounts.*.bank_name' => 'nullable|string|max:255',
            'bankAccounts.*.account_number' => 'nullable|string|max:255',
        ]);

        $this->company->update([
            'name' => $validated['editName'],
            'short_name' => $validated['editShortName'] ?: null,
            'tax_id' => $validated['editTaxId'] ?: null,
            'registration_number' => $validated['editRegistrationNumber'] ?: null,
            'nkd_code' => $validated['editNkdCode'] ?: null,
            'nkd_name' => $validated['editNkdName'] ?: null,
            'email' => $validated['editEmail'] ?: null,
            'phone' => $validated['editPhone'] ?: null,
            'website' => $validated['editWebsite'] ?: null,
            'address' => $validated['editAddress'] ?: null,
            'director_name' => $validated['editDirectorName'] ?: null,
            'director_phone' => $validated['editDirectorPhone'] ?: null,
            'director_email' => $validated['editDirectorEmail'] ?: null,
            'is_vat_registered' => $validated['editIsVatRegistered'],
        ]);

        $keptRows = collect($validated['bankAccounts'])
            ->filter(fn ($row) => trim((string) ($row['bank_name'] ?? '')) !== '' || trim((string) ($row['account_number'] ?? '')) !== '')
            ->values()
            ->take(5);

        $this->company->bankAccounts()->delete();
        foreach ($keptRows as $index => $row) {
            $this->company->bankAccounts()->create([
                'bank_name' => $row['bank_name'] ?: null,
                'account_number' => $row['account_number'] ?: null,
                'position' => $index,
            ]);
        }

        $this->editing = false;
    }

    public function render()
    {
        return view('livewire.company-dashboard');
    }
}
