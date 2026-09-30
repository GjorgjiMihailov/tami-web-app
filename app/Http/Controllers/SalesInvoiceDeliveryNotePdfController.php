<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\Invoicing\DeliveryNoteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SalesInvoiceDeliveryNotePdfController extends Controller
{
    public function __invoke(Company $company, SalesInvoice $salesInvoice, DeliveryNoteService $deliveryNotes)
    {
        Gate::authorize('view', $salesInvoice);

        abort_if($salesInvoice->company_id !== $company->id, 404);
        abort_unless($salesInvoice->status === 'confirmed', 403, 'Само потврдена фактура може да добие испратница.');

        $salesInvoice->load(['lines.item', 'partner']);

        $deliveryNote = $deliveryNotes->createOrGetFor($salesInvoice);

        $pdf = Pdf::loadView('pdf.delivery-note', [
            'deliveryNote' => $deliveryNote,
            'company' => $company,
            'partner' => $salesInvoice->partner,
            'lines' => $salesInvoice->lines,
            'sourceLabel' => 'фактура',
            'sourceNumber' => $salesInvoice->formattedNumber(),
        ]);

        return $pdf->download('ispratnica-'.Str::slug($deliveryNote->delivery_note_number_formatted, '-', 'mk').'.pdf');
    }
}
