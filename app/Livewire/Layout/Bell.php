<?php

namespace App\Livewire\Layout;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\Form743;
use App\Services\CompanyDashboardQuery;
use App\Support\Form743Status;
use App\Support\Menu;
use App\Support\WorkingYear;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Ѕвончето на горната лента: што чека на овој човек во тековната фирма.
 *
 * Лениво (#[Lazy]): броењето ги вчитува фактурите на годината, па страницата
 * прво се исцртува, а бројката дополнително се дотура. Без кеш намерно —
 * платена фактура мора да исчезне од списокот веднаш.
 *
 * Секое известување се нуди само ако екранот на кој води е видлив за овој
 * човек (Menu::visiblePatterns), исто како брзите дејства.
 */
#[Lazy]
class Bell extends Component
{
    public ?Company $company = null;

    public function mount(?Company $company = null): void
    {
        $company ??= request()->route('company');
        $this->company = $company instanceof Company ? $company : null;
    }

    public function placeholder(): string
    {
        return '<div class="h-9 w-9" aria-hidden="true"></div>';
    }

    // Смената на годината ја менува сметката; празен метод е доволен — самото
    // примање на настанот ја освежува компонентата.
    #[On('working-year-changed')]
    public function refreshForYear(): void {}

    /**
     * @return list<array{label: string, count: int, url: string}>
     */
    public function items(): array
    {
        $user = auth()->user();

        if (! $this->company || ! $user || ! $user->visibleCompanies()->whereKey($this->company->id)->exists()) {
            return [];
        }

        $company = $this->company;
        $year = WorkingYear::for($company);
        $visible = Menu::visiblePatterns($user, $company);
        $items = [];

        if (isset($visible['sales-invoices.*'])) {
            $items[] = [
                'label' => 'Задоцнети излезни фактури',
                'count' => CompanyDashboardQuery::receivableOverdueCount($company, $year),
                'url' => route('sales-invoices.index', $company),
            ];
            $items[] = [
                'label' => 'Неуспешни е-Фактура испраќања',
                'count' => CompanyDashboardQuery::efakturaFailed($company, $year),
                'url' => route('sales-invoices.index', $company),
            ];
        }

        if (isset($visible['purchase-invoices.*'])) {
            $items[] = [
                'label' => 'Нови влезни е-Фактури за прифаќање',
                'count' => IncomingEfakturaDocument::where('company_id', $company->id)->whereNull('decision')->count(),
                'url' => route('purchase-invoices.index', $company),
            ];
            $items[] = [
                'label' => 'Задоцнети влезни фактури',
                'count' => CompanyDashboardQuery::payableOverdueCount($company, $year),
                'url' => route('purchase-invoices.index', $company),
            ];
        }

        if (isset($visible['form743.*'])) {
            $items[] = [
                'label' => 'Необработени 743 обрасци',
                'count' => Form743::where('company_id', $company->id)->where('status', Form743Status::PENDING->value)->count(),
                'url' => route('form743.index', $company),
            ];
        }

        return array_values(array_filter($items, fn (array $item) => $item['count'] > 0));
    }

    public function render()
    {
        $items = $this->items();

        return view('livewire.layout.bell', [
            'items' => $items,
            'total' => array_sum(array_column($items, 'count')),
        ]);
    }
}
