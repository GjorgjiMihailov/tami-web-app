<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            // Другата страна како што ја пишува банката: сметка и име (латиница).
            // Нема ЕДБ — банката не го дава.
            $table->string('counterparty_account', 34)->nullable();
            $table->string('counterparty_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropColumn(['counterparty_account', 'counterparty_name']);
        });
    }
};
