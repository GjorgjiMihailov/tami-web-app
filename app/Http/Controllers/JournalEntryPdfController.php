<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\JournalEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;

class JournalEntryPdfController extends Controller
{
    public function __invoke(Company $company, JournalEntry $journalEntry)
    {
        Gate::authorize('view', $journalEntry);

        abort_if($journalEntry->company_id !== $company->id, 404);

        $journalEntry->load(['lines.account', 'lines.partner', 'journalGroup', 'company']);

        $pdf = Pdf::loadView('pdf.journal-entry', ['entry' => $journalEntry]);

        return $pdf->download("nalog-{$journalEntry->displayNumber()}.pdf");
    }
}
