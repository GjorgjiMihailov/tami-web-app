<?php

namespace App\Http\Controllers;

use App\Exports\SalesInvoiceImportTemplateExport;
use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;

class SalesInvoiceImportTemplateController extends Controller
{
    public function __invoke(Company $company)
    {
        Gate::authorize('view', $company);
        abort_unless(auth()->user()->hasAnyRole(['admin', 'accountant']), 403);

        return Excel::download(new SalesInvoiceImportTemplateExport, 'fakturi-obrazec.xlsx');
    }
}
