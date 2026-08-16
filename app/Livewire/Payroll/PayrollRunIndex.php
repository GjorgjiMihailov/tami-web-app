<?php

namespace App\Livewire\Payroll;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollRunService;
use App\Support\WorkingYear;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.app')]
class PayrollRunIndex extends Component
{
    public Company $company;

    public ?int $newMonth = null;

    public function mount(Company $company): void
    {
        Gate::authorize('view', $company);
        $this->company = $company;
    }

    public function createRun(PayrollRunService $service): mixed
    {
        $this->validate([
            'newMonth' => ['required', 'integer', 'min:1', 'max:12'],
        ], attributes: ['newMonth' => 'месец']);

        $year = WorkingYear::for($this->company);

        // A missing hour fund and a month opened twice are both ordinary
        // mistakes, not faults — they belong on the field, not in a 500.
        try {
            $run = $service->open($this->company, $year, $this->newMonth);
        } catch (QueryException $e) {
            // Must come first. QueryException extends PDOException, which
            // extends RuntimeException, so the broader catch below would
            // swallow it and show the user a raw SQLSTATE string instead of
            // this sentence.
            $this->addError('newMonth', 'За тој месец веќе постои пресметка.');

            return null;
        } catch (RuntimeException $e) {
            $this->addError('newMonth', $e->getMessage());

            return null;
        }

        return $this->redirect(route('payroll-runs.show', [$this->company, $run]));
    }

    public function render()
    {
        $year = WorkingYear::for($this->company);

        $runs = PayrollRun::where('company_id', $this->company->id)
            ->where('year', $year)
            ->withCount('employees')
            ->with('employees')
            ->orderBy('month')
            ->get();

        return view('livewire.payroll.payroll-run-index', [
            'runs' => $runs,
            'year' => $year,
        ]);
    }
}
