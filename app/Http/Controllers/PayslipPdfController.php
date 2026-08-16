<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PayslipPdfController extends Controller
{
    public function __invoke(Company $company, PayrollRun $run, PayrollRunEmployee $runEmployee): Response
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);
        abort_unless($runEmployee->payroll_run_id === $run->id, 404);

        $runEmployee->load(['employee', 'lines']);

        $pdf = Pdf::loadView('pdf.payslip', [
            'company' => $company,
            'run' => $run,
            'runEmployee' => $runEmployee,
        ]);

        return $pdf->download("isplatna-lista-{$run->year}-{$run->month}-{$runEmployee->employee->last_name}.pdf");
    }
}
