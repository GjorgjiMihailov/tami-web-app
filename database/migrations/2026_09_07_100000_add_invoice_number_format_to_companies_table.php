<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Стандардните вредности даваат точно ГГГГ/Н — форматот што досега
            // беше зашиен во кодот. Ниту една постоечка фирма не си го менува
            // изгледот на фактурите кога оваа миграција ќе помине.
            $table->string('invoice_number_prefix', 10)->nullable()->after('invoice_footer_note');
            $table->boolean('invoice_number_include_year')->default(true)->after('invoice_number_prefix');
            $table->boolean('invoice_number_year_first')->default(true)->after('invoice_number_include_year');
            $table->unsignedTinyInteger('invoice_number_year_digits')->default(4)->after('invoice_number_year_first');
            $table->string('invoice_number_separator', 3)->default('/')->after('invoice_number_year_digits');
            $table->unsignedTinyInteger('invoice_number_padding')->default(1)->after('invoice_number_separator');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_number_prefix',
                'invoice_number_include_year',
                'invoice_number_year_first',
                'invoice_number_year_digits',
                'invoice_number_separator',
                'invoice_number_padding',
            ]);
        });
    }
};
