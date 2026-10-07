<?php

use App\Services\Inventory\StockImportShareBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $result = app(StockImportShareBackfill::class)->run();

        if ($result['skipped'] > 0) {
            logger()->warning("Залиха по извор: {$result['skipped']} артикли прескокнати (пресметаното салдо не се совпаѓа со салдото).");
        }
    }

    public function down(): void {}
};
