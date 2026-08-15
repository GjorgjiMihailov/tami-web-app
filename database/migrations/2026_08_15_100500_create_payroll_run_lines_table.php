<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_employee_id')->constrained('payroll_run_employees', 'id', 'payroll_run_lines_employee_fk')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('code', 16)->nullable();     // SifraTipRabotenCas
            $table->string('description');
            // Whole hours: BrojCasovi is xs:int in mpin.xsd.
            $table->unsignedSmallInteger('hours')->nullable();
            $table->decimal('percent', 8, 2)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('borne_by', 16)->default('employer');
            $table->boolean('is_automatic')->default(false);
            $table->timestamps();

            $table->index('payroll_run_employee_id', 'payroll_run_lines_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_lines');
    }
};
