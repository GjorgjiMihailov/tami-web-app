<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PayrollRun;
use App\Support\Payroll\Mpin\MpinDocumentBuilder;
use App\Support\Payroll\Mpin\MpinValidator;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class MpinExportController extends Controller
{
    public function __invoke(Company $company, PayrollRun $run): Response
    {
        Gate::authorize('view', $company);
        abort_unless($run->company_id === $company->id, 404);

        $result = MpinValidator::check($run);

        if (! $result->passes()) {
            // Грешките се прикажуваат на самиот екран на пресметката, не тука:
            // ова е симнување, а симнувањето не може да рендерира порака.
            return back()->with('mpin_errors', $result->errors);
        }

        $run->forceFill([
            'mpin_exported_at' => now(),
            'mpin_exported_by' => auth()->id(),
        ])->save();

        $xml = MpinDocumentBuilder::build($run);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.MpinDocumentBuilder::fileName($run).'"',
        ]);
    }
}
