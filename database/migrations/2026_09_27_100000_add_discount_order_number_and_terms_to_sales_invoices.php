<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            // Број на профактура или нарачка на купувачот; се полни при претворање.
            $table->string('order_number', 100)->nullable()->after('invoice_number_formatted');
            $table->text('terms')->nullable()->after('notes');
        });

        // Рабат во проценти по ставка. Нула = без рабат, па постојните фактури
        // се пресметуваат исто како порано.
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('vat_rate');
        });

        Schema::table('proforma_invoice_lines', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('proforma_invoice_lines', fn (Blueprint $table) => $table->dropColumn('discount_percent'));
        Schema::table('sales_invoice_lines', fn (Blueprint $table) => $table->dropColumn('discount_percent'));
        Schema::table('sales_invoices', fn (Blueprint $table) => $table->dropColumn(['order_number', 'terms']));
    }
};
