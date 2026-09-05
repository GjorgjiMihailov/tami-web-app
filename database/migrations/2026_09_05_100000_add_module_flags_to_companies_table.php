<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Стандардно вклучено, за да ниту еден постоечки профил не се
            // смени кога оваа миграција ќе помине. Изборот е нешто што админ
            // го прави свесно; стандардната вредност никогаш не смее да
            // затвори екран што вчера бил отворен.
            $table->boolean('uses_material')->default(true)->after('type');
            $table->boolean('uses_stock')->default(true)->after('uses_material');
            $table->boolean('uses_payroll')->default(true)->after('uses_stock');
            $table->boolean('uses_finance')->default(true)->after('uses_payroll');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['uses_material', 'uses_stock', 'uses_payroll', 'uses_finance']);
        });
    }
};
