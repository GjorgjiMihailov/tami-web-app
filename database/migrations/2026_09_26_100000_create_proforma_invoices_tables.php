<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Профактурата има своја серија, но ја дели годината, разделникот и
            // должината на бројот со фактурите. Само префиксот е одделен.
            $table->string('proforma_number_prefix', 10)->nullable()->default('ПФ-')->after('invoice_number_padding');
        });

        Schema::create('proforma_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('proforma_number');
            $table->string('proforma_number_formatted', 40);
            $table->string('reference', 100)->nullable();
            $table->date('proforma_date');
            $table->date('expected_delivery_date')->nullable();
            // null = не е одредено; 0 = по приемот; инаку број на денови.
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->string('currency', 3)->default('MKD');
            $table->string('status', 20)->default('draft'); // draft | confirmed | converted | cancelled
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year', 'proforma_number']);
        });

        Schema::create('proforma_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proforma_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_invoice_lines');
        Schema::dropIfExists('proforma_invoices');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('proforma_number_prefix');
        });
    }
};
