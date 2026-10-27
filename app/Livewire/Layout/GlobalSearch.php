<?php

namespace App\Livewire\Layout;

use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Support\Menu;
use Livewire\Component;

/**
 * Пребарувањето на горната лента (Ctrl+K): една кутија за фактури и
 * кооперанти на тековната фирма, а на порталот (без фирма) за самите клиенти.
 *
 * Резултатите се ограничени на фирмите што овој човек смее да ги гледа, а
 * секоја група се нуди само ако екранот ѝ е видлив во менито.
 */
class GlobalSearch extends Component
{
    public ?Company $company = null;

    public string $query = '';

    private const PER_GROUP = 5;

    public function mount(?Company $company = null): void
    {
        $company ??= request()->route('company');
        $this->company = $company instanceof Company ? $company : null;
    }

    /** Џокерите на LIKE во внесот се обичен текст, не шаблон. */
    private function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    /**
     * @return list<array{title: string, rows: list<array{primary: string, secondary: string, url: string}>}>
     */
    public function groups(): array
    {
        $term = trim($this->query);
        $user = auth()->user();

        if (mb_strlen($term) < 2 || ! $user) {
            return [];
        }

        $like = $this->like($term);

        if (! $this->company) {
            $clients = $user->visibleCompanies()
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('tax_id', 'like', $like))
                ->orderBy('name')
                ->limit(self::PER_GROUP)
                ->get(['id', 'name', 'tax_id']);

            return $clients->isEmpty() ? [] : [[
                'title' => 'Клиенти',
                'rows' => $clients->map(fn (Company $c) => [
                    'primary' => $c->name,
                    'secondary' => (string) $c->tax_id,
                    'url' => route('companies.dashboard', $c),
                ])->all(),
            ]];
        }

        // Фирмата е зачувана во компонентата, но правото се проверува при
        // секое пребарување — не се верува на состојбата од прелистувачот.
        if (! $user->visibleCompanies()->whereKey($this->company->id)->exists()) {
            return [];
        }

        $company = $this->company;
        $visible = Menu::visiblePatterns($user, $company);
        $groups = [];

        if (isset($visible['sales-invoices.*'])) {
            $rows = SalesInvoice::query()
                ->where('company_id', $company->id)
                ->where(fn ($q) => $q
                    ->where('invoice_number_formatted', 'like', $like)
                    ->orWhereHas('partner', fn ($p) => $p->where('name', 'like', $like)))
                ->with('partner')
                ->latest('id')
                ->limit(self::PER_GROUP)
                ->get();

            if ($rows->isNotEmpty()) {
                $groups[] = ['title' => 'Излезни фактури', 'rows' => $rows->map(fn (SalesInvoice $i) => [
                    'primary' => $i->formattedNumber() ?? 'Нацрт',
                    'secondary' => (string) $i->partner?->name,
                    'url' => route('sales-invoices.show', [$company, $i]),
                ])->all()];
            }
        }

        if (isset($visible['purchase-invoices.*'])) {
            $rows = PurchaseInvoice::query()
                ->where('company_id', $company->id)
                ->where(fn ($q) => $q
                    ->where('supplier_invoice_number', 'like', $like)
                    ->orWhereHas('partner', fn ($p) => $p->where('name', 'like', $like)))
                ->with('partner')
                ->latest('id')
                ->limit(self::PER_GROUP)
                ->get();

            if ($rows->isNotEmpty()) {
                $groups[] = ['title' => 'Влезни фактури', 'rows' => $rows->map(fn (PurchaseInvoice $i) => [
                    'primary' => $i->supplier_invoice_number ?: 'Без број',
                    'secondary' => (string) $i->partner?->name,
                    'url' => route('purchase-invoices.show', [$company, $i]),
                ])->all()];
            }
        }

        if (isset($visible['partners.*'])) {
            $rows = Partner::query()
                ->where('company_id', $company->id)
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('tax_id', 'like', $like))
                ->orderBy('name')
                ->limit(self::PER_GROUP)
                ->get();

            if ($rows->isNotEmpty()) {
                $groups[] = ['title' => 'Кооперанти', 'rows' => $rows->map(fn (Partner $p) => [
                    'primary' => $p->name,
                    'secondary' => (string) $p->tax_id,
                    'url' => route('partners.show', [$company, $p]),
                ])->all()];
            }
        }

        return $groups;
    }

    public function render()
    {
        return view('livewire.layout.global-search', ['groups' => $this->groups()]);
    }
}
