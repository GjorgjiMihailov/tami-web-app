<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Цената со ДДВ се чува само кога е внесена токму таа (како
            // unit_price_gross на ставка од фактура): нето заокружено на две
            // децимали не се враќа точно во бруто (120,00 → 101,69 → 119,99).
            $table->decimal('selling_price_gross', 12, 2)->nullable()->after('selling_price');
            $table->decimal('cost_price_gross', 12, 2)->nullable()->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['selling_price_gross', 'cost_price_gross']);
        });
    }
};
