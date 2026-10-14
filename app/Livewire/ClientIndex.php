<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesClientProfiles;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
    use ManagesClientProfiles;

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('admin'), 403);
    }

    /**
     * Колку што има внесено по фирма: излезни и влезни фактури, изводи, налози.
     *
     * @param  list<int>  $ids
     * @return array<int, array{sales: int, purchases: int, statements: int, entries: int}>
     */
    private function dataCounts(array $ids): array
    {
        $counts = [];

        foreach ($ids as $id) {
            $counts[$id] = ['sales' => 0, 'purchases' => 0, 'statements' => 0, 'entries' => 0];
        }

        foreach (['sales' => 'sales_invoices', 'purchases' => 'purchase_invoices', 'statements' => 'bank_statements', 'entries' => 'journal_entries'] as $key => $table) {
            DB::table($table)->whereIn('company_id', $ids)->selectRaw('company_id, count(*) as total')->groupBy('company_id')
                ->get()->each(function ($row) use (&$counts, $key) {
                    $counts[$row->company_id][$key] = (int) $row->total;
                });
        }

        return $counts;
    }

    public function render()
    {
        $accountants = User::role('accountant')
            ->with(['latestInvitation', 'assignedCompanies' => fn ($query) => $query->orderBy('name')])
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
        $accounts = User::with('latestInvitation')
            ->whereIn('company_id', $companies->pluck('id'))
            ->orderBy('id')
            ->get()
            ->unique('company_id')
            ->keyBy('company_id');

        // Фирмите без своја сметка не се во горната листа, па без овој дел
        // админ не можеше да ги види ни да ги избрише. Со податоците што ги
        // имаат — за да се знае која од две исти фирми е вистинската.
        $workedCompanies = Company::with(['accountants' => fn ($query) => $query->orderBy('name')])
            ->whereNotIn('id', $companies->pluck('id'))
            ->orderBy('name')
            ->get();

        $allCompanies = Company::get(['id', 'name', 'tax_id']);
        $nameKey = fn (?string $name) => mb_strtolower(trim((string) $name));
        $duplicateNames = $allCompanies->groupBy(fn (Company $c) => $nameKey($c->name))
            ->filter(fn ($group, $key) => $key !== '' && $group->count() > 1)->keys()->all();
        $duplicateTaxIds = $allCompanies->filter(fn (Company $c) => trim((string) $c->tax_id) !== '')
            ->groupBy(fn (Company $c) => trim((string) $c->tax_id))
            ->filter(fn ($group) => $group->count() > 1)->keys()->all();

        $isDuplicate = fn (Company $company) => in_array($nameKey($company->name), $duplicateNames, true)
            || in_array(trim((string) $company->tax_id), $duplicateTaxIds, true);

        return view('livewire.client-index', [
            'accountants' => $accountants,
            'companies' => $companies,
            'accounts' => $accounts,
            'workedCompanies' => $workedCompanies,
            'dataCounts' => $this->dataCounts($workedCompanies->pluck('id')->all()),
            'isDuplicate' => $isDuplicate,
        ]);
    }
}
