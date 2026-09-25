<?php

namespace App\Livewire\Invoicing;

use App\Exceptions\InvalidInvoiceStateException;
use App\Models\Company;
use App\Models\ProformaInvoice;
use App\Services\Invoicing\ProformaService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class ProformaIndex extends Component
{
    public const FILTERS = ['all', 'draft', 'confirmed', 'converted', 'cancelled'];

    public Company $company;

    public string $search = '';

    #[Url(as: 'filter')]
    public string $filter = 'all';

    #[Url(as: 'proforma')]
    public ?int $selectedId = null;

    public string $error = '';

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function select(int $proformaId): void
    {
        $this->selectedId = ProformaInvoice::where('company_id', $this->company->id)->findOrFail($proformaId)->id;
        $this->error = '';
    }

    public function closeDetail(): void
    {
        $this->selectedId = null;
    }

    public function markConfirmed(int $proformaId): void
    {
        $proforma = $this->owned($proformaId);
        Gate::authorize('update', $proforma);

        $this->run(fn () => app(ProformaService::class)->confirm($proforma));
    }

    public function cancelProforma(int $proformaId): void
    {
        $proforma = $this->owned($proformaId);
        Gate::authorize('update', $proforma);

        $this->run(fn () => app(ProformaService::class)->cancel($proforma));
    }

    private function owned(int $proformaId): ProformaInvoice
    {
        return ProformaInvoice::where('company_id', $this->company->id)->findOrFail($proformaId);
    }

    private function run(callable $action): void
    {
        $this->error = '';

        try {
            $action();
        } catch (InvalidInvoiceStateException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : 'all';

        $proformas = ProformaInvoice::where('company_id', $this->company->id)
            ->when($filter !== 'all', fn ($q) => $q->where('status', $filter))
            ->when($this->search, fn ($q) => $q->where(fn ($q2) => $q2
                ->where('proforma_number_formatted', 'like', "%{$this->search}%")
                ->orWhere('reference', 'like', "%{$this->search}%")
                ->orWhereHas('partner', fn ($p) => $p->where('name', 'like', "%{$this->search}%"))))
            ->with(['partner:id,name', 'lines'])
            ->orderByDesc('proforma_date')->orderByDesc('id')
            ->get();

        // Избраната се бара по фирма, не само по id — туѓо id од адресата дава празно.
        $selected = $this->selectedId
            ? ProformaInvoice::where('company_id', $this->company->id)->with(['partner', 'lines.item', 'salesInvoice'])->find($this->selectedId)
            : null;

        return view('livewire.invoicing.proforma-index', [
            'proformas' => $proformas,
            'hasProformas' => ProformaInvoice::where('company_id', $this->company->id)->exists(),
            'filter' => $filter,
            'selected' => $selected,
        ]);
    }
}
