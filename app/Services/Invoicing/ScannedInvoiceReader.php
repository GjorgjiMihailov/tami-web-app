<?php

namespace App\Services\Invoicing;

use App\Models\Company;
use Illuminate\Http\UploadedFile;

/**
 * Единствениот влез кон читањето на скен.
 *
 * Постои за да може целата серија тестови да работи со двојник — ниту еден
 * тест не смее да праќа фајл надвор, ниту да троши пари.
 */
interface ScannedInvoiceReader
{
    /**
     * @throws ScannedInvoiceReadException кога фајлот не може да се прочита
     */
    public function read(UploadedFile $file, Company $company): ScannedInvoice;
}
