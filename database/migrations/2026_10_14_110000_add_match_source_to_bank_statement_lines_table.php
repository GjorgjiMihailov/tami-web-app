<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            // Од каде е предлогот: „memory“ = запомнето од претходно книжење.
            // Групното потврдување важи само за предлози од таков извор.
            $table->string('match_source', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropColumn('match_source');
        });
    }
};
