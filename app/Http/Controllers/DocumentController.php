<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __invoke(Company $company, Document $document)
    {
        if ($document->company_id !== $company->id) {
            abort(404);
        }

        Gate::authorize('view', $document->documentable);

        return Storage::disk('google')->download($document->path, $document->original_filename);
    }
}
