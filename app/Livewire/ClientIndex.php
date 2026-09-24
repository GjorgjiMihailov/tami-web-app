<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * „Клиенти" — единствената врата на админот до профилите.
 *
 * Еден список за сите профили: сметководителите (со клиентите за кои работат)
 * и клиентите (со сметководителот што ги води). Копчињата за нов профил се
 * овде, а не во менито — менито на админот има само Почетна, Клиенти и
 * Поставки.
 */
#[Layout('layouts.app')]
class ClientIndex extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);
    }

    public function render()
    {
        $accountants = User::role('accountant')
            ->with(['assignedCompanies' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();

        // „Клиенти" се профилите на порталот. Фирмите без своја сметка ги
        // работи сметководител — тие се гледаат под него („Работи за"), не тука.
        $companies = Company::with(['accountants' => fn ($query) => $query->orderBy('name')])
            ->whereIn('id', User::whereNotNull('company_id')->select('company_id'))
            ->orderBy('name')
            ->get();

        // Една сметка по фирма е нормален случај; ако ги има повеќе, се
        // покажува најстарата (онаа што фирмата ја отворила).
        $accounts = User::whereIn('company_id', $companies->pluck('id'))
            ->orderBy('id')
            ->get()
            ->unique('company_id')
            ->keyBy('company_id');

        return view('livewire.client-index', [
            'accountants' => $accountants,
            'companies' => $companies,
            'accounts' => $accounts,
        ]);
    }
}
