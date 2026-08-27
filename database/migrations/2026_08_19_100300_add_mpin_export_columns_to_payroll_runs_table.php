<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->timestamp('mpin_exported_at')->nullable();
            $table->foreignId('mpin_exported_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mpin_exported_by');
            $table->dropColumn('mpin_exported_at');
        });
    }
};
