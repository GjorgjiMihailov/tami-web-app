<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->boolean('is_import')->default(false)->after('order_number');
            $table->string('customs_declaration_number')->nullable()->after('is_import');
            $table->date('import_date')->nullable()->after('customs_declaration_number');
            $table->string('import_currency_code', 3)->nullable()->after('import_date');
            $table->decimal('import_exchange_rate', 12, 4)->nullable()->after('import_currency_code');
        });

        Schema::create('purchase_invoice_import_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('payee_name');
            $table->string('reference_number')->nullable();
            $table->decimal('foreign_amount', 14, 2)->nullable();
            $table->decimal('base_amount', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('purchase_invoice_tariff_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('tariff_code');
            $table->decimal('foreign_amount', 14, 2)->nullable();
            $table->decimal('customs_duty', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_tariff_lines');
        Schema::dropIfExists('purchase_invoice_import_costs');

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn(['is_import', 'customs_declaration_number', 'import_date', 'import_currency_code', 'import_exchange_rate']);
        });
    }
};
