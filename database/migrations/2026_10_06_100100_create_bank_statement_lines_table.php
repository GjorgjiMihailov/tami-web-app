<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->date('line_date');
            $table->string('direction', 3);
            $table->decimal('amount', 15, 2);
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->string('reference_number', 64)->nullable();
            $table->string('purpose_code', 16)->nullable();
            $table->string('kind', 16);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_invoice_payment_id')->nullable()->constrained('sales_invoice_payments')->nullOnDelete();
            $table->foreignId('purchase_invoice_payment_id')->nullable()->constrained('purchase_invoice_payments')->nullOnDelete();
            // Точно ако плаќањето го создала самата ставка — тогаш „Отвори за измена“ го брише.
            $table->boolean('created_payment')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
