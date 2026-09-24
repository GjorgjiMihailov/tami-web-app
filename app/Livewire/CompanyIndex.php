<?php

namespace App\Livewire;

use App\Livewire\Concerns\SendsInvitations;
use App\Models\Company;
use App\Rules\ValidEmbg;
use App\Services\CompanyCreator;
use App\Support\CompanyType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CompanyIndex extends Component
{
    use SendsInvitations;

    public string $newName = '';

    public string $newType = '';

    public string $newTaxId = '';

    public string $newEmbg = '';

    public string $newContactName = '';

    public string $newEmail = '';

    public ?int $createdCompanyId = null;

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
            // Е-пошта на клиентот: фирмата добива сметка за најава (покана),
            // преку која клиентот внесува фактури, потпишува, качува изводи.
            'newEmail' => 'required|email|max:255|unique:users,email',
            'newContactName' => 'nullable|string|max:255',
            'newEmbg' => $this->newType === CompanyType::INDIVIDUAL->value && $this->newEmbg !== ''
                ? ['nullable', 'max:13', new ValidEmbg]
                : ['nullable', 'max:13'],
        ], [
            'newEmail.unique' => 'Оваа е-пошта веќе има сметка во порталот.',
        ]);

        // Вредностите што зависат од типот живеат во CompanyCreator — истото
        // место што го користи и екранот за прв клиент. Две копии од таа
        // листа се разидуваат, а разликата се гледа дури на печатена фактура.
        $type = CompanyType::from($validated['newType']);

        $account = DB::transaction(function () use ($validated, $type) {
            $company = CompanyCreator::create(
                $validated['newName'],
                $type,
                $validated['newTaxId'],
                $validated['newEmbg'],
                auth()->user(),
            );

            return CompanyCreator::createLogin(
                $company,
                // Кај физичко лице фирмата и човекот се исто име.
                $type->isLegal() && filled($validated['newContactName'])
                    ? $validated['newContactName']
                    : $validated['newName'],
                $validated['newEmail'],
            );
        });

        $this->reset(['newName', 'newType', 'newTaxId', 'newEmbg', 'newContactName', 'newEmail']);

        // Линкот на поканата се гледа само еднаш, па се остава на овој екран.
        $this->createdCompanyId = $account->company_id;
        $this->sendInvitation($account);
    }

    public function render()
    {
        $companies = auth()->user()->visibleCompanies()->orderBy('name')->get();

        return view('livewire.company-index', [
            'companies' => $companies,
            'createdCompany' => $this->createdCompanyId ? Company::find($this->createdCompanyId) : null,
        ]);
    }
}
