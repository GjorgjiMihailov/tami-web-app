<?php

namespace App\Livewire;

use App\Models\Company;
use App\Rules\ValidEmbg;
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
            // ЕМБГ се проверува со контролна цифра само кога навистина е внесен
            // и кога типот е физичко лице — истиот образец како во
            // App\Livewire\CompanyProfile::save(). Профил може да се создаде и
            // без ЕМБГ, па да се дополни подоцна во профилот.
            'newEmbg' => $this->newType === CompanyType::INDIVIDUAL->value && $this->newEmbg !== ''
                ? ['nullable', 'max:13', new ValidEmbg]
                : ['nullable', 'max:13'],
        ]);

        $type = CompanyType::from($validated['newType']);
        $isLegal = $type->isLegal();

        // Ниту едно поле што зависи од типот не смее да остане на стандардна
        // вредност од базата — инаку физичко лице засекогаш останува ДДВ
        // обврзник и на фактурата излегува ДДВ што не постои. Причината е
        // опишана во docs/superpowers/specs/2026-08-21-client-profile-types-design.md.
        $company = Company::create([
            'name' => $validated['newName'],
            'type' => $type,
            'tax_id' => $isLegal ? ($validated['newTaxId'] ?: null) : null,
            'embg' => $isLegal ? null : ($validated['newEmbg'] ?: null),
            'is_vat_registered' => $isLegal,
            // Сите модули вклучени; се исклучуваат на картичката „Модули".
            'uses_material' => true,
            'uses_stock' => true,
            'uses_payroll' => true,
            'uses_finance' => true,
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM,
        ]);

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
