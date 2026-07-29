<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PartnerShow extends Component
{
    public Company $company;

    public Partner $partner;

    public bool $editing = false;

    public string $editName = '';

    public string $editType = 'legal_entity';

    public string $editTaxId = '';

    public string $editRegistrationNumber = '';

    public string $editDirectorName = '';

    public bool $editIsVatRegistered = false;

    public string $editVatNumber = '';

    public string $editEmail = '';

    public string $editPhone = '';

    public string $editAddress = '';

    public array $bankAccounts = [];

    public function mount(Company $company, Partner $partner): void
    {
        Gate::authorize('view', $partner);

        if ($partner->company_id !== $company->id) {
            abort(404);
        }

        $this->company = $company;
        $this->partner = $partner;
    }

    public function startEdit(): void
    {
        Gate::authorize('update', $this->partner);

        $this->editName = $this->partner->name;
        $this->editType = $this->partner->type;
        $this->editTaxId = (string) $this->partner->tax_id;
        $this->editRegistrationNumber = (string) $this->partner->registration_number;
        $this->editDirectorName = (string) $this->partner->director_name;
        $this->editIsVatRegistered = $this->partner->is_vat_registered;
        $this->editVatNumber = (string) $this->partner->vat_number;
        $this->editEmail = (string) $this->partner->email;
        $this->editPhone = (string) $this->partner->phone;
        $this->editAddress = (string) $this->partner->address;

        $existing = $this->partner->bankAccounts()->get();
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
        Gate::authorize('update', $this->partner);

        $validated = $this->validate([
            'editName' => 'required|string|max:255',
            'editType' => ['required', Rule::in(['individual', 'legal_entity'])],
            'editTaxId' => 'nullable|string|max:255',
            'editRegistrationNumber' => 'nullable|string|max:255',
            'editDirectorName' => 'nullable|string|max:255',
            'editIsVatRegistered' => 'boolean',
            'editVatNumber' => 'nullable|string|max:255',
            'editEmail' => 'nullable|email|max:255',
            'editPhone' => 'nullable|string|max:255',
            'editAddress' => 'nullable|string|max:255',
            'bankAccounts' => 'array|max:5',
            'bankAccounts.*.bank_name' => 'nullable|string|max:255',
            'bankAccounts.*.account_number' => 'nullable|string|max:255',
        ]);

        $isLegalEntity = $validated['editType'] === 'legal_entity';
        $isVatRegistered = $isLegalEntity && $validated['editIsVatRegistered'];

        DB::transaction(function () use ($validated, $isLegalEntity, $isVatRegistered) {
            $this->partner->update([
                'name' => $validated['editName'],
                'type' => $validated['editType'],
                'tax_id' => $validated['editTaxId'] ?: null,
                'registration_number' => $isLegalEntity ? ($validated['editRegistrationNumber'] ?: null) : null,
                'director_name' => $isLegalEntity ? ($validated['editDirectorName'] ?: null) : null,
                'is_vat_registered' => $isVatRegistered,
                'vat_number' => $isVatRegistered ? ($validated['editVatNumber'] ?: null) : null,
                'email' => $validated['editEmail'] ?: null,
                'phone' => $validated['editPhone'] ?: null,
                'address' => $validated['editAddress'] ?: null,
            ]);

            $keptRows = collect($validated['bankAccounts'])
                ->filter(fn ($row) => trim((string) ($row['bank_name'] ?? '')) !== '' || trim((string) ($row['account_number'] ?? '')) !== '')
                ->values()
                ->take(5);

            $this->partner->bankAccounts()->delete();
            foreach ($keptRows as $index => $row) {
                $this->partner->bankAccounts()->create([
                    'bank_name' => $row['bank_name'] ?: null,
                    'account_number' => $row['account_number'] ?: null,
                    'position' => $index,
                ]);
            }
        });

        $this->editing = false;
    }

    public function render()
    {
        return view('livewire.partner-show');
    }
}
