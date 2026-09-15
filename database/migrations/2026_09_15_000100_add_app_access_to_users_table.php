<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Стандардно вклучени: миграцијата не смее да одземе пристап на никого што
     * денес работи. Затворањето е свесен чекор на админот, не нуспојава.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('app_prodazba')->default(true);
            $table->boolean('app_finansii')->default(true);
            $table->boolean('app_plata')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_prodazba', 'app_finansii', 'app_plata']);
        });
    }
};
