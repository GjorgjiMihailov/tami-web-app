<?php

namespace App\Livewire\Reports;

use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The ФИНАНСИИ → Извештаи и обрасци landing page.
 *
 * The menu is exactly two levels deep, so every report is a button here
 * rather than a third-level menu entry.
 */
#[Layout('layouts.app')]
class ReportIndex extends Component
{
    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function render()
    {
        return view('livewire.reports.report-index', [
            'available' => [
                ['label' => 'ДДВ-04', 'description' => 'Пресметка на данок на додадена вредност за период.', 'url' => route('reports.ddv04', $this->company)],
                ['label' => 'Бруто биланс', 'description' => 'Промет и салда по конта за период.', 'url' => route('accounting.reports.trial-balance', $this->company)],
                ['label' => 'Аналитичка картица', 'description' => 'Ставки по конто или по комитент за период.', 'url' => route('accounting.reports.ledger-card', $this->company)],
            ],
            'soon' => [
                ['label' => 'МДБ', 'description' => 'Месечен даночен биланс.'],
                ['label' => 'Завршна сметка', 'description' => 'Годишна завршна сметка.'],
                ['label' => 'Солвентност', 'description' => 'Извештај за солвентност.'],
            ],
        ]);
    }
}
