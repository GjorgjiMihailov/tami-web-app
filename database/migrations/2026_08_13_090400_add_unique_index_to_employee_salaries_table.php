<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One employee has at most one agreed salary per effective date.
     *
     * The form's updateOrCreate is meant to enforce this, but a lookup key that
     * did not match its own stored row appended duplicates instead of updating,
     * and salaryOn() then returned an arbitrary one of them. The application
     * fix is in EmployeeForm; this is the guarantee underneath it.
     *
     * Added here rather than in the create migration, which may already have run
     * elsewhere.
     */
    public function up(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            // Explicit short name — this project has hit MySQL's 64-character
            // identifier limit three times.
            $table->unique(['employee_id', 'effective_from'], 'employee_salaries_date_unique');

            // The plain index this replaces covered exactly the same columns in
            // the same order. Dropped after the unique one exists, so the
            // employee_id foreign key never loses its supporting index.
            $table->dropIndex('employee_salaries_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('employee_salaries', function (Blueprint $table) {
            $table->index(['employee_id', 'effective_from'], 'employee_salaries_lookup_index');
            $table->dropUnique('employee_salaries_date_unique');
        });
    }
};
