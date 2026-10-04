<?php

namespace Tests\Support;

use App\Models\Company;
use App\Services\Invoicing\CustomsDeclarationReader;
use App\Services\Invoicing\ScannedCustomsDeclaration;
use Illuminate\Http\UploadedFile;

/** Двојник за тестови; статичките полиња се чистат со `reset()` во `setUp()`. */
class FakeCustomsDeclarationReader implements CustomsDeclarationReader
{
    public static ?ScannedCustomsDeclaration $next = null;

    public static ?\Throwable $throws = null;

    public static function reset(): void
    {
        self::$next = null;
        self::$throws = null;
    }

    public function read(UploadedFile $file, Company $company): ScannedCustomsDeclaration
    {
        if (self::$throws !== null) {
            throw self::$throws;
        }

        return self::$next ?? new ScannedCustomsDeclaration;
    }
}
