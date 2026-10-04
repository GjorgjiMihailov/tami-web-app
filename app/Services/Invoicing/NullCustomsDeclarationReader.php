<?php

namespace App\Services\Invoicing;

use App\Models\Company;
use Illuminate\Http\UploadedFile;

/** Врзан кога нема клуч за Anthropic — врзувањето мора да успее и без клуч. */
class NullCustomsDeclarationReader implements CustomsDeclarationReader
{
    public function read(UploadedFile $file, Company $company): ScannedCustomsDeclaration
    {
        throw new ScannedInvoiceReadException('Читањето скен не е подесено на овој сервер.');
    }
}
