<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Form743;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Го враќа фајлот на еден 743 образец.
 *
 * Постои одделно од `DocumentController` затоа што таа рута живее во групата
 * `documents.`, која е затворена за физичко лице. Овде патеката води преку
 * самиот образец, па нема како да се дојде до туѓ документ на истата фирма.
 */
class Form743DocumentController extends Controller
{
    public function __invoke(Company $company, Form743 $form743)
    {
        if ($form743->company_id !== $company->id) {
            abort(404);
        }

        Gate::authorize('view', $form743);

        $document = $form743->documents()->latest()->first();

        abort_if($document === null, 404);

        return Storage::disk('google')->download($document->path, $document->original_filename);
    }
}
