<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Стандардната вредност ги опишува постоечките редови: секој профил
            // отворен пред оваа колона е правно лице, зашто друг вид немаше.
            // Останува како стандардна и потоа — формата за нов профил бара
            // изречен избор, па стандардната никогаш не решава наместо човек.
            $table->string('type', 16)->default('legal')->after('short_name');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
