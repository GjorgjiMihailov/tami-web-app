<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_run_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();

            foreach ([
                'gross', 'pension', 'health', 'injury', 'unemployment', 'contributions',
                'tax_base', 'tax', 'net', 'deductions_total', 'effective_net',
                'top_up_pension', 'top_up_health', 'top_up_injury', 'top_up_unemployment',
                'top_up', 'hourly_rate', 'full_month_gross',
            ] as $column) {
                $table->decimal($column, 15, 2)->default(0);
            }

            $table->unsignedSmallInteger('seniority_years')->default(0);
            $table->timestamps();

            // Short explicit name: the generated one would be
            // payroll_run_employees_payroll_run_id_employee_id_unique at 58
            // characters, close enough to MySQL's 64-character limit to be
            // worth not relying on.
            $table->unique(['payroll_run_id', 'employee_id'], 'payroll_run_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_employees');
    }
};
