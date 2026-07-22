<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->string('vat_treatment', 30)->default('standard')->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            $table->dropColumn('vat_treatment');
        });
    }
};
