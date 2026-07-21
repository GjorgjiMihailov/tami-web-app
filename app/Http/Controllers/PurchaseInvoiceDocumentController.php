<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class PurchaseInvoiceDocumentController extends Controller
{
    public function __invoke(Company $company, PurchaseInvoice $purchaseInvoice)
    {
        Gate::authorize('view', $purchaseInvoice);

        if ($purchaseInvoice->company_id !== $company->id) {
            abort(404);
        }

        if ($purchaseInvoice->source_document_path === null) {
            abort(404, 'No document attached to this purchase invoice.');
        }

        return Storage::disk('google')->download($purchaseInvoice->source_document_path);
    }
}
