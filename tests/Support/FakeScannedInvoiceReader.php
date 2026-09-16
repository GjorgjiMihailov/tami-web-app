<?php

namespace Tests\Support;

use App\Models\Company;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceReader;
use Illuminate\Http\UploadedFile;

/**
 * Читач за тестови. Статичките полиња се чистат во `setUp()` на секој тест што
 * го користи — инаку нагодување од еден тест би протекло во следниот.
 */
class FakeScannedInvoiceReader implements ScannedInvoiceReader
{
    public static ?ScannedInvoice $next = null;

    public static ?\Throwable $throws = null;

    public static function reset(): void
    {
        self::$next = null;
        self::$throws = null;
    }

    public function read(UploadedFile $file, Company $company): ScannedInvoice
    {
        if (self::$throws !== null) {
            throw self::$throws;
        }

        return self::$next ?? new ScannedInvoice;
    }
}
