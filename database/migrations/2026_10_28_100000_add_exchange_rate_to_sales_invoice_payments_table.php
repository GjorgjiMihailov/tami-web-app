<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_payments', function (Blueprint $table) {
            // Курс на денот на приливот; само девизна наплата, денарската останува null.
            $table->decimal('exchange_rate', 12, 6)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoice_payments', function (Blueprint $table) {
            $table->dropColumn('exchange_rate');
        });
    }
};
