<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Nullable намерно: постоечките фирми немаат вид обврзник додека
            // корисникот не го внесе, а проверката пред извоз го бара тоа —
            // подобро отколку тивко да претпоставиме 110 за сите.
            $table->string('mpin_obvrznik_code', 8)->nullable()->after('tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('mpin_obvrznik_code');
        });
    }
};
