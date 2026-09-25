<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ProformaInvoice;
use App\Support\InvoiceLanguage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProformaPdfController extends Controller
{
    public function __invoke(Company $company, ProformaInvoice $proforma)
    {
        Gate::authorize('view', $company);
        Gate::authorize('view', $proforma);

        // URL-от носи две независни id-ња — профактурата мора да е на оваа фирма.
        abort_unless($proforma->company_id === $company->id, 404);

        $proforma->load(['lines.item', 'partner', 'company']);

        // Англиски јазик важи само за физичко лице, исто како кај фактурата.
        $language = $company->type->isIndividual() ? $proforma->partner->invoice_language : InvoiceLanguage::MK;

        $pdf = Pdf::loadView('pdf.proforma-invoice', ['proforma' => $proforma, 'company' => $company, 'lang' => $language]);

        return $pdf->download('profaktura-'.Str::slug($proforma->proforma_number_formatted, '-', 'mk').'.pdf');
    }
}
