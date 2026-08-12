<?php

namespace App\Livewire\Concerns;

use App\Support\WorkingYear;
use Livewire\Attributes\On;

/**
 * For list screens that must re-scope themselves when the sidebar's year
 * selector changes. Each using component still sets $workingYear itself in
 * mount() — the trait only owns the live update and the date boundaries.
 */
trait InteractsWithWorkingYear
{
    public int $workingYear = 0;

    #[On('working-year-changed')]
    public function onWorkingYearChanged(int $year): void
    {
        $this->workingYear = $year;

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    public function workingYearStart(): string
    {
        return WorkingYear::startOf($this->workingYear);
    }

    public function workingYearEnd(): string
    {
        return WorkingYear::endOf($this->workingYear);
    }
}
