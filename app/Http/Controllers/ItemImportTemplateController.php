<?php

namespace App\Http\Controllers;

use App\Exports\ItemImportTemplateExport;
use App\Models\Company;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;

class ItemImportTemplateController extends Controller
{
    public function __invoke(Company $company)
    {
        Gate::authorize('view', $company);

        return Excel::download(new ItemImportTemplateExport(), 'artikli-obrazec.xlsx');
    }
}
