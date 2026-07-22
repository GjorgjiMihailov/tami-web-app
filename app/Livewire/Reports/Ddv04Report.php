<?php

namespace App\Livewire\Reports;

use App\Models\Company;
use App\Services\Reports\Ddv04Query;
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

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    public function render()
    {
        $fields = Ddv04Query::run($this->company, Carbon::parse($this->from), Carbon::parse($this->to));

        return view('livewire.reports.ddv04-report', ['fields' => $fields]);
    }
}
