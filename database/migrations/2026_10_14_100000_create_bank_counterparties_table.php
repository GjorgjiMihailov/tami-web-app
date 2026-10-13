<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_counterparties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // AccountNumber::key — сметка без цртички, IBAN сведен на домашен број.
            $table->string('account_key', 34);
            // Истата сметка има различна намена по насока (провизија што излегува
            // наспроти поврат што влегува; позајмица наспроти враќање).
            $table->string('direction', 3);
            $table->string('name_seen')->nullable();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            // Конто по основ за ставки од оваа сметка (само аналитичко).
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'account_key', 'direction'], 'bank_cp_company_key_dir_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_counterparties');
    }
};
