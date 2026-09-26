<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            // Број на нарачка (кај нас или кај добавувачот).
            $table->string('order_number', 100)->nullable()->after('supplier_invoice_number');
        });

        // Рабат во проценти по ставка. Нула = без рабат, па постојните фактури
        // се пресметуваат исто како порано.
        Schema::table('purchase_invoice_lines', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_lines', fn (Blueprint $table) => $table->dropColumn('discount_percent'));
        Schema::table('purchase_invoices', fn (Blueprint $table) => $table->dropColumn('order_number'));
    }
};
