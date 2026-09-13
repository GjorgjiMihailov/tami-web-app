<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            // Стандардните вредности ја репродуцираат денешната состојба, па
            // ниту една постоечка фактура не си го менува ниту изгледот ниту
            // книжењето: сите остануваат денарски и на македонски.
            $table->string('language', 2)->default('mk')->after('notes');
            $table->string('currency', 3)->default('MKD')->after('language');
            $table->decimal('exchange_rate', 12, 6)->default(1)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(['language', 'currency', 'exchange_rate']);
        });
    }
};
