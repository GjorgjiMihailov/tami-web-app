<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            // Дел од вредноста на залихата (количина × просечна цена) што е на 6601 (увоз).
            $table->decimal('import_value', 18, 6)->default(0)->after('average_cost');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // Дел од вредноста на движењето што е од/кон 6601 (увоз).
            $table->decimal('import_value', 18, 6)->default(0)->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('import_value'));
        Schema::table('stock_levels', fn (Blueprint $table) => $table->dropColumn('import_value'));
    }
};
