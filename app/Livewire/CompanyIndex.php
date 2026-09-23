<?php

namespace App\Livewire;

use App\Models\Company;
use App\Rules\ValidEmbg;
use App\Services\CompanyCreator;
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

    public string $newEmbg = '';

    public function mount(): void
    {
        // Фирми — сега достапен и за сметководител, скроен на неговите
        // фирми преку visibleCompanies() во render(). Само клиент останува
        // надвор.
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);
    }

    public function addCompany(): void
    {
        Gate::authorize('create', Company::class);

        $validated = $this->validate([
            'newName' => 'required|string|max:255',
            'newType' => ['required', Rule::enum(CompanyType::class)],
            'newTaxId' => 'nullable|string|max:255',
            // ЕМБГ се проверува со контролна цифра само кога навистина е внесен
            // и кога типот е физичко лице — истиот образец како во
            // App\Livewire\CompanyProfile::save(). Профил може да се создаде и
            // без ЕМБГ, па да се дополни подоцна во профилот.
            'newEmbg' => $this->newType === CompanyType::INDIVIDUAL->value && $this->newEmbg !== ''
                ? ['nullable', 'max:13', new ValidEmbg]
                : ['nullable', 'max:13'],
        ]);

        // Вредностите што зависат од типот живеат во CompanyCreator — истото
        // место што го користи и екранот за прв клиент. Две копии од таа
        // листа се разидуваат, а разликата се гледа дури на печатена фактура.
        $company = CompanyCreator::create(
            $validated['newName'],
            CompanyType::from($validated['newType']),
            $validated['newTaxId'],
            $validated['newEmbg'],
            auth()->user(),
        );

        $this->reset(['newName', 'newType', 'newTaxId', 'newEmbg']);

        // Остатокот од податоците се дополнува на профилот — таму е и
        // единствената форма за нив.
        $this->redirect(route('companies.profile', $company), navigate: true);
    }

    public function render()
    {
        $companies = auth()->user()->visibleCompanies()->orderBy('name')->get();

        return view('livewire.company-index', ['companies' => $companies]);
    }
}
