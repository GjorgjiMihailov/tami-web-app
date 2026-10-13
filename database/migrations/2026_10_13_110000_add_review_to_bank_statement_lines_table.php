<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            // Колку програмата е сигурна во ставката и зошто; null = рачно внесена.
            $table->string('match_confidence', 8)->nullable();
            $table->string('match_reason')->nullable();
            // Кога сметководителот ја потврдил ставката при проверка една по една.
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropColumn(['match_confidence', 'match_reason', 'reviewed_at']);
        });
    }
};
