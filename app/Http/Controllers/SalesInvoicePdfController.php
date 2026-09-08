<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SalesInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;

class SalesInvoicePdfController extends Controller
{
    public function __invoke(Company $company, SalesInvoice $salesInvoice)
    {
        Gate::authorize('view', $salesInvoice);

        abort_if($salesInvoice->company_id !== $company->id, 404);
        abort_if($salesInvoice->status !== 'confirmed', 403, 'Only confirmed invoices can be downloaded as PDF.');

        $salesInvoice->load(['lines', 'partner', 'company.bankAccounts']);

        $pdf = Pdf::loadView('pdf.sales-invoice', ['invoice' => $salesInvoice]);

        // Форматираниот број може да содржи коса црта, а таа не смее во име на
        // датотека. Сè што не е буква, цифра или цртичка станува цртичка.
        $slug = preg_replace('/[^\p{L}\p{N}-]+/u', '-', $salesInvoice->formattedNumber());

        return $pdf->download("invoice-{$slug}.pdf");
    }
}
