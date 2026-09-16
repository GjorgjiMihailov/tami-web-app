<?php

namespace App\Services\Invoicing;

use App\Models\Company;
use Illuminate\Http\UploadedFile;

/**
 * Читачот што не чита ништо.
 *
 * Стои врзан кога нема клуч за Anthropic. Формата и онака не го покажува
 * копчето без клуч, но врзувањето мора да успее и на сервер без клуч —
 * инаку целата апликација паѓа при подигање.
 */
class NullScannedInvoiceReader implements ScannedInvoiceReader
{
    public function read(UploadedFile $file, Company $company): ScannedInvoice
    {
        throw new ScannedInvoiceReadException('Читањето скен не е подесено на овој сервер.');
    }
}
