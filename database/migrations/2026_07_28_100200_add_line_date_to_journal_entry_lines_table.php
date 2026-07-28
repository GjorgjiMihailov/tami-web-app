<?php

use App\Models\JournalEntryLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->date('line_date')->nullable()->after('journal_entry_id');
        });

        JournalEntryLine::with('journalEntry')->get()->each(function (JournalEntryLine $line) {
            $line->update(['line_date' => $line->journalEntry->entry_date]);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropColumn('line_date');
        });
    }
};
