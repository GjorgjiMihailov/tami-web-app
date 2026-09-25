<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\Partner;
use App\Services\PartnerInsights;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class PartnerIndex extends Component
{
    public const FILTERS = ['all', 'owed', 'legal_entity', 'individual'];

    public const TABS = ['overview', 'transactions', 'statement'];

    public Company $company;

    public string $search = '';

    #[Url(as: 'filter')]
    public string $filter = 'all';

    #[Url(as: 'partner')]
    public ?int $selectedId = null;

    #[Url]
    public string $tab = 'overview';

    #[Url(as: 'status')]
    public string $invoiceStatus = 'all';

    #[Url]
    public string $period = 'this_month';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function select(int $partnerId): void
    {
        $this->selectedId = Partner::where('company_id', $this->company->id)->findOrFail($partnerId)->id;
        $this->tab = 'overview';
    }

    public function closeDetail(): void
    {
        $this->selectedId = null;
    }

    public function render()
    {
        // Вредностите од адресата не се доверливи — непозната се враќа на стандардна.
        $filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : 'all';
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'overview';
        $invoiceStatus = in_array($this->invoiceStatus, PartnerInsights::INVOICE_FILTERS, true) ? $this->invoiceStatus : 'all';
        $period = in_array($this->period, PartnerInsights::PERIODS, true) ? $this->period : 'this_month';

        $owed = PartnerInsights::outstandingByPartner($this->company);

        $partners = Partner::where('company_id', $this->company->id)
            ->when(in_array($filter, ['legal_entity', 'individual'], true), fn ($q) => $q->where('type', $filter))
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2->where('name', 'like', "%{$this->search}%")->orWhere('tax_id', 'like', "%{$this->search}%")))
            ->orderBy('name')
            ->get();

        if ($filter === 'owed') {
            $partners = $partners->filter(fn (Partner $partner) => collect($owed[$partner->id] ?? [])->contains(fn ($amount) => bccomp($amount, '0', 2) > 0))->values();
        }

        // Избраниот кооперант се бара по фирма, не само по id — туѓо id од адресата дава празно.
        $selected = $this->selectedId
            ? Partner::where('company_id', $this->company->id)->with(['bankAccounts', 'contacts'])->find($this->selectedId)
            : null;

        return view('livewire.partner-index', [
            'partners' => $partners,
            'hasPartners' => Partner::where('company_id', $this->company->id)->exists(),
            'owed' => $owed,
            'selected' => $selected,
            'filter' => $filter,
            'tab' => $tab,
            'invoiceStatus' => $invoiceStatus,
            'period' => $period,
            'receivables' => $selected && $tab === 'overview' ? PartnerInsights::receivables($selected) : collect(),
            'payables' => $selected && $tab === 'overview' ? PartnerInsights::payables($selected) : '0.00',
            'salesInvoices' => $selected && $tab === 'transactions' ? PartnerInsights::salesInvoices($selected, $invoiceStatus) : collect(),
            'salesPayments' => $selected && $tab === 'transactions' ? PartnerInsights::salesPayments($selected) : collect(),
            'purchaseInvoices' => $selected && $tab === 'transactions' ? PartnerInsights::purchaseInvoices($selected) : collect(),
            'purchasePayments' => $selected && $tab === 'transactions' ? PartnerInsights::purchasePayments($selected) : collect(),
            'statement' => $selected && $tab === 'statement' ? PartnerInsights::statement($selected, $period) : null,
        ]);
    }
}
