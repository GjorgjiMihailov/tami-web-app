<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Partner;
use App\Services\PartnerInsights;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PartnerStatementPdfController extends Controller
{
    public function __invoke(Request $request, Company $company, Partner $partner)
    {
        Gate::authorize('view', $company);
        Gate::authorize('view', $partner);

        // URL-от носи две независни id-ња — кооперантот мора да е на оваа фирма.
        abort_unless($partner->company_id === $company->id, 404);

        $period = in_array($request->query('period'), PartnerInsights::PERIODS, true) ? $request->query('period') : 'this_month';

        $pdf = Pdf::loadView('pdf.partner-statement', [
            'company' => $company,
            'partner' => $partner,
            'statement' => PartnerInsights::statement($partner, $period),
        ]);

        return $pdf->download('izvod-'.Str::slug($partner->name, '-', 'mk').'.pdf');
    }
}
