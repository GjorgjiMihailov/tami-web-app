<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            // Само девизен извод; износите на неговите ставки се во оваа валута.
            $table->string('currency', 3)->nullable()->after('kind');
        });

        Schema::table('bank_statement_lines', function (Blueprint $table) {
            // Девизен извод: курс на денот. Денарски извод, ставка на 1039: девизниот износ и валутата.
            $table->string('currency', 3)->nullable();
            $table->decimal('exchange_rate', 12, 6)->nullable();
            $table->decimal('foreign_amount', 15, 2)->nullable();
            // Сметководителот го прифатил предлогот за курсна разлика на 1039.
            $table->boolean('settle_difference')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate', 'foreign_amount', 'settle_difference']);
        });

        Schema::table('bank_statements', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
