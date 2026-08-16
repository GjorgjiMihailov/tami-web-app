<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Months, not years: someone joining with 7 years and 6 months of
            // service would otherwise cross a минат труд threshold half a year
            // late.
            $table->unsignedSmallInteger('prior_service_months')->default(0)->after('weekly_hours');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('prior_service_months');
        });
    }
};
