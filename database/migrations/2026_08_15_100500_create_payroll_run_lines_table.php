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

            // The explicit index is registered before constrained(): on
            // MySQL InnoDB, a foreign key with no index already covering its
            // column auto-creates one. Registering it after constrained()
            // would leave two indexes on the same column — invisible on
            // SQLite, which does not have this auto-create behaviour.
            $table->unsignedBigInteger('payroll_run_employee_id');
            $table->index('payroll_run_employee_id', 'payroll_run_lines_employee_idx');
            $table->foreign('payroll_run_employee_id', 'payroll_run_lines_employee_fk')
                ->references('id')->on('payroll_run_employees')->cascadeOnDelete();

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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_lines');
    }
};
