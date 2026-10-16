<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->boolean('is_imported')->default(false);
            $table->decimal('imported_total', 15, 2)->nullable();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('invoice_number_next')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(['is_imported', 'imported_total']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('invoice_number_next');
        });
    }
};
