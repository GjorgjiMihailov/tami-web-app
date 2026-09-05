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

    public string $newEmail = '';

    public string $newPhone = '';

    public string $newAddress = '';

    // Стандардно сѐ вклучено: најчестиот случај е клиент што користи сѐ, а
    // отштиклирањето е свесен потег.
    public bool $newUsesMaterial = true;

    public bool $newUsesStock = true;

    public bool $newUsesPayroll = true;

    public bool $newUsesFinance = true;

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
            'newEmail' => 'nullable|email|max:255',
            'newPhone' => 'nullable|string|max:255',
            'newAddress' => 'nullable|string|max:255',
            'newUsesMaterial' => 'boolean',
            'newUsesStock' => 'boolean',
            'newUsesPayroll' => 'boolean',
            'newUsesFinance' => 'boolean',
        ]);

        $type = CompanyType::from($validated['newType']);
        $isLegal = $type->isLegal();

        // Модулите важат само за правно лице. Кај физичко лице формата не ги ни
        // прикажува, па вредноста заостаната во компонентата од претходен избор
        // на тип не смее да заврши во базата — истата замка како кај ЕДБ/ЕМБГ и
        // `is_vat_registered` подолу.
        $usesMaterial = $isLegal ? $validated['newUsesMaterial'] : true;

        // Полињата по тип се решени во табелата „Полиња по тип" во
        // docs/superpowers/specs/2026-08-21-client-profile-types-design.md.
        //
        // Ниту едно поле што зависи од типот не смее да се остави на default од
        // базата. `is_vat_registered` има default `true` во миграцијата, а
        // формата за уредување на профил го запишува само за правно лице —
        // значи ако тука не се постави изречно, физичкото лице засекогаш
        // останува ДДВ обврзник и на фактурата на закупецот излегува ДДВ што
        // не постои. Ниту еден екран потоа не може тоа да го врати.
        //
        // Од истата причина ЕДБ и ЕМБГ се запишуваат само на својот тип:
        // формата ги менува полињата кога типот ќе се смени, но веќе внесената
        // вредност останува во компонентата, а полето на погрешниот тип потоа
        // е скриено во профилот и не може да се поправи.
        Company::create([
            'name' => $validated['newName'],
            'type' => $type,
            'tax_id' => $isLegal ? ($validated['newTaxId'] ?: null) : null,
            'embg' => $isLegal ? null : ($validated['newEmbg'] ?: null),
            'email' => $validated['newEmail'] ?: null,
            'phone' => $validated['newPhone'] ?: null,
            'address' => $validated['newAddress'] ?: null,
            'is_vat_registered' => $isLegal,
            'uses_material' => $usesMaterial,
            // Залиха без Материјално не постои. Се запишува исклучена без
            // разлика што дошло од формата.
            'uses_stock' => $usesMaterial && ($isLegal ? $validated['newUsesStock'] : true),
            'uses_payroll' => $isLegal ? $validated['newUsesPayroll'] : true,
            'uses_finance' => $isLegal ? $validated['newUsesFinance'] : true,
            // Колоната е NOT NULL и нема вредност што значи „не важи". За
            // физичко лице 'firm' е најблиску до вистината — нема сопствени
            // акредитиви — а пристапот и онака е затворен со чуварот по тип во
            // CompanyProfile и со EnsureLegalEntity врз е-Фактура рутите.
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM,
        ]);

        $this->reset(['newName', 'newType', 'newTaxId', 'newEmbg', 'newEmail', 'newPhone', 'newAddress',
            'newUsesMaterial', 'newUsesStock', 'newUsesPayroll', 'newUsesFinance']);
    }

    public function render()
    {
        $companies = auth()->user()->visibleCompanies()->orderBy('name')->get();

        return view('livewire.company-index', ['companies' => $companies]);
    }
}
