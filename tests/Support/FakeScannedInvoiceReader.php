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

    /** Незадолжително: одговори по ред, за тест што чита повеќе документи со еден читач. */
    public static array $queue = [];

    public static function reset(): void
    {
        self::$next = null;
        self::$throws = null;
        self::$queue = [];
    }

    public function read(UploadedFile $file, Company $company): ScannedInvoice
    {
        if (self::$throws !== null) {
            throw self::$throws;
        }

        if (self::$queue !== []) {
            return array_shift(self::$queue);
        }

        return self::$next ?? new ScannedInvoice;
    }
}
