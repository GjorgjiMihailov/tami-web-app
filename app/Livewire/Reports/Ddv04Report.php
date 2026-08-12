<?php

namespace App\Livewire\Reports;

use App\Models\Company;
use App\Services\Reports\Ddv04Query;
use App\Support\WorkingYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Ddv04Report extends Component
{
    public Company $company;

    public string $from = '';

    public string $to = '';

    public int $workingYear = 0;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);

        // ДДВ-04 is a monthly return, so it opens on a month, never a whole
        // year: this month when working in the current year, December of the
        // year otherwise.
        $this->from = $this->workingYear === (int) now()->year
            ? now()->startOfMonth()->toDateString()
            : sprintf('%04d-12-01', $this->workingYear);
        $this->to = WorkingYear::defaultDate($this->workingYear);
    }

    public function render()
    {
        $fields = Ddv04Query::run($this->company, Carbon::parse($this->from), Carbon::parse($this->to));

        return view('livewire.reports.ddv04-report', ['fields' => $fields]);
    }
}
