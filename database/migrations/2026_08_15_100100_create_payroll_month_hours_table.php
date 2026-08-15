<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_month_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('hours');
            $table->timestamps();

            $table->unique(['year', 'month'], 'payroll_month_hours_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_month_hours');
    }
};
