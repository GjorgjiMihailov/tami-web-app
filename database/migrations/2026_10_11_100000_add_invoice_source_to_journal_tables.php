<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Месечен налог на фактури: повеќе фактури делат еден налог, па ставката
        // мора да знае од која фактура е (за бришење, менување на број и за
        // сметката на побарувањето). `auto_kind` го означува самиот налог.
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('auto_kind', 32)->nullable()->after('description');
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->string('source_type', 32)->nullable()->after('journal_entry_id');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['source_type', 'source_id'], 'journal_entry_lines_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex('journal_entry_lines_source_index');
            $table->dropColumn(['source_type', 'source_id']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn('auto_kind');
        });
    }
};
