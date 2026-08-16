<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 16)->default('draft');

            // Frozen when the run opens. A parameter change next January must
            // not silently restate a July run that has already been filed.
            $table->unsignedSmallInteger('month_hours');
            $table->foreignId('payroll_parameter_id')->constrained();

            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'year', 'month'], 'payroll_runs_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
