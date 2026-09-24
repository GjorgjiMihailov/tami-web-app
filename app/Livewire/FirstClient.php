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

/**
 * Првиот клиент на нов сметководител.
 *
 * Постои зашто сметководител без ниту една фирма беше заглавен: најавата го
 * носеше на екранот „Изберете фирма", а единствената врска таму водеше на
 * companies.index, кој за него враќа 403.
 *
 * Екранот сам се брани од двете страни. Кој нема работа тука — админ, клиент,
 * или сметководител што веќе има фирма — се враќа на dashboard. Без тоа,
 * страната би станала заден влез за создавање фирми.
 */
#[Layout('layouts.app')]
class FirstClient extends Component
{
    use SendsInvitations;

    public string $name = '';

    public string $type = '';

    public string $taxId = '';

    public string $embg = '';

    public string $contactName = '';

    public string $email = '';

    public ?int $createdCompanyId = null;

    public function mount()
    {
        $user = auth()->user();

        if (! $user->hasRole('accountant') || $user->visibleCompanies()->exists()) {
            return $this->redirect(route('dashboard'));
        }

        return null;
    }

    public function save()
    {
        // Истата брана како на екранот на админот (CompanyIndex::addCompany()
        // вика Gate::authorize исто вака). Правилото живее во CompanyPolicy,
        // не тука.
        Gate::authorize('create', Company::class);

        $validated = $this->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::enum(CompanyType::class)],
            'taxId' => 'nullable|string|max:255',
            // ЕМБГ се проверува со контролна цифра само кога навистина е внесен
            // и кога типот е физичко лице — истиот образец како во
            // App\Livewire\CompanyIndex::addCompany().
            'embg' => $this->type === CompanyType::INDIVIDUAL->value && $this->embg !== ''
                ? ['nullable', 'max:13', new ValidEmbg]
                : ['nullable', 'max:13'],
            'contactName' => 'nullable|string|max:255',
            // Без е-пошта клиентот нема како да се најави и да работи.
            'email' => 'required|email|max:255|unique:users,email',
        ], [
            'email.unique' => 'Оваа е-пошта веќе има сметка во порталот.',
        ]);

        $type = CompanyType::from($validated['type']);

        $account = DB::transaction(function () use ($validated, $type) {
            $company = CompanyCreator::create(
                $validated['name'],
                $type,
                $validated['taxId'],
                $validated['embg'],
                auth()->user(),
            );

            return CompanyCreator::createLogin(
                $company,
                $type->isLegal() && filled($validated['contactName'])
                    ? $validated['contactName']
                    : $validated['name'],
                $validated['email'],
            );
        });

        // Линкот на поканата се гледа само еднаш, па се остава на овој екран.
        $this->createdCompanyId = $account->company_id;
        $this->sendInvitation($account);
        $this->reset(['name', 'type', 'taxId', 'embg', 'contactName', 'email']);
    }

    public function render()
    {
        return view('livewire.first-client', [
            'types' => CompanyType::cases(),
            'createdCompany' => $this->createdCompanyId ? Company::find($this->createdCompanyId) : null,
        ]);
    }
}
