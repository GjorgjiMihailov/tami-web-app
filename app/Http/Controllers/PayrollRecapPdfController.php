<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PayrollRun;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PayrollRecapPdfController extends Controller
{
    public function __invoke(Company $company, PayrollRun $run): Response
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);

        $run->load(['employees.employee', 'employees.lines']);

        $pdf = Pdf::loadView('pdf.payroll-recap', [
            'company' => $company,
            'run' => $run,
        ])->setPaper('a4', 'landscape');

        return $pdf->download("rekapitular-{$run->year}-{$run->month}.pdf");
    }
}
