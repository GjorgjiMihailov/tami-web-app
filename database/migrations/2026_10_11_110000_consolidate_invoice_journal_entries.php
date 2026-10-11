<?php

use App\Services\Posting\InvoiceJournalConsolidator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Старите налози „еден по фактура“ (група 99) → месечни налози во
        // групите 30 (излезни) и 20 (влезни). Само ставките се преместуваат.
        app(InvoiceJournalConsolidator::class)->run();
    }

    public function down(): void
    {
        // Еднонасочно: оригиналните налози по фактура не се чуваат.
    }
};
