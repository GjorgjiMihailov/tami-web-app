<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Partner;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;

class PartnerListPdfController extends Controller
{
    public function __invoke(Company $company)
    {
        Gate::authorize('view', $company);

        $partners = Partner::where('company_id', $company->id)->orderBy('name')->get();

        $pdf = Pdf::loadView('pdf.partner-list', ['company' => $company, 'partners' => $partners]);

        return $pdf->download('partneri.pdf');
    }
}
