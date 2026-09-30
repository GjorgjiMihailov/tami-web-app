<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ProformaInvoice;
use App\Services\Invoicing\DeliveryNoteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProformaDeliveryNotePdfController extends Controller
{
    public function __invoke(Company $company, ProformaInvoice $proforma, DeliveryNoteService $deliveryNotes)
    {
        Gate::authorize('view', $company);
        Gate::authorize('view', $proforma);

        abort_unless($proforma->company_id === $company->id, 404);
        abort_unless($proforma->status === 'confirmed', 403, 'Само потврдена профактура може да добие испратница.');

        $proforma->load(['lines.item', 'partner']);

        $deliveryNote = $deliveryNotes->createOrGetFor($proforma);

        $pdf = Pdf::loadView('pdf.delivery-note', [
            'deliveryNote' => $deliveryNote,
            'company' => $company,
            'partner' => $proforma->partner,
            'lines' => $proforma->lines,
            'sourceLabel' => 'профактура',
            'sourceNumber' => $proforma->proforma_number_formatted,
        ]);

        return $pdf->download('ispratnica-'.Str::slug($deliveryNote->delivery_note_number_formatted, '-', 'mk').'.pdf');
    }
}
