<?php

use App\Services\Posting\JournalGroupRelocator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Плата → 50; банкарски плаќања → 10; изводи по вид → 10 / 11.
        app(JournalGroupRelocator::class)->run();
    }

    public function down(): void
    {
        // Еднонасочно.
    }
};
