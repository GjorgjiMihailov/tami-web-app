<?php

namespace App\Livewire\Apps;

use App\Livewire\Concerns\InteractsWithWorkingYear;
use App\Models\Company;
use App\Support\CompanyModule;
use App\Support\WorkingYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Влезниот екран на апликацијата Продажба.
 *
 * Порано кликот на „Продажба" паѓаше право во Излезни фактури, зашто
 * AppSwitcher ја земаше буквално првата ставка од менито.
 */
#[Layout('layouts.app')]
class SalesDashboard extends Component
{
    use InteractsWithWorkingYear;

    public Company $company;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);
    }

    /**
     * Трите копчиња. Условите се исчитани од Menu::legalTree() и
     * Menu::individualTree(): кај физичко лице „Излезни фактури" и
     * „Кооперанти" воопшто немаат модул, па ништо не ги гаси, а влезни
     * фактури таму не постојат.
     *
     * Иконите се испишани цели зашто се украс, како во
     * company-dashboard.blade.php.
     *
     * @return list<array{key: string, label: string, url: string, tone: string, icon: string}>
     */
    public function links(): array
    {
        $legal = $this->company->type->isLegal();
        $material = ! $legal || $this->company->usesModule(CompanyModule::MATERIAL);

        $links = [];

        if ($material) {
            $links[] = [
                'key' => 'sales',
                'label' => 'Излезни фактури',
                'url' => route('sales-invoices.index', $this->company),
                'tone' => 'board-link--orange',
                'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.6a1 1 0 01.7.3l4.4 4.4a1 1 0 01.3.7V19a2 2 0 01-2 2z',
            ];
        }

        if ($legal && $material) {
            $links[] = [
                'key' => 'purchases',
                'label' => 'Влезни фактури',
                'url' => route('purchase-invoices.index', $this->company),
                'tone' => 'board-link--green',
                'icon' => 'M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.6a1 1 0 00-.9.55l-.8 1.9a1 1 0 01-.9.55h-3.6a1 1 0 01-.9-.55l-.8-1.9a1 1 0 00-.9-.55H4',
            ];
        }

        $links[] = [
            'key' => 'partners',
            'label' => 'Кооперанти',
            'url' => route('partners.index', $this->company),
            'tone' => 'board-link--indigo',
            'icon' => 'M17 20h5v-2a3 3 0 00-5.4-1.9M17 20H7m10 0v-2c0-.7-.1-1.3-.4-1.9M7 20H2v-2a3 3 0 015.4-1.9M7 20v-2c0-.7.1-1.3.4-1.9m0 0a5 5 0 019.2 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
        ];

        return $links;
    }

    public function render()
    {
        return view('livewire.apps.sales-dashboard');
    }
}
