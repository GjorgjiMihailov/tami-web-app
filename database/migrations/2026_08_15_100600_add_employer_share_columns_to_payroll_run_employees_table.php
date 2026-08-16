<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->decimal('employer_gross', 15, 2)->default(0)->after('full_month_gross');
            $table->decimal('employer_contributions', 15, 2)->default(0)->after('employer_gross');
            $table->decimal('employer_tax', 15, 2)->default(0)->after('employer_contributions');
            $table->decimal('employer_net', 15, 2)->default(0)->after('employer_tax');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->dropColumn(['employer_gross', 'employer_contributions', 'employer_tax', 'employer_net']);
        });
    }
};
