<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('director_embg', 13)->nullable()->after('director_name');
            $table->boolean('uses_foreign_currency')->default(false);

            // Податоци за плата (МПИН): кој го поднесува и каде се контактира.
            $table->string('payroll_obligation_code', 16)->nullable();
            $table->string('payroll_authorized_person')->nullable();
            $table->string('payroll_phone_prefix', 8)->nullable();
            $table->string('payroll_phone', 32)->nullable();
            $table->string('payroll_mobile', 32)->nullable();
            $table->string('payroll_municipality_code', 16)->nullable();
        });

        // Досега IBAN/SWIFT се внесуваа без знамено — фирма што веќе ги има
        // остана девизна, за да не ѝ исчезнат од формуларот.
        $withForeign = DB::table('company_bank_accounts')
            ->where(fn ($q) => $q->whereNotNull('iban')->orWhereNotNull('swift'))
            ->pluck('company_id');

        DB::table('companies')->whereIn('id', $withForeign)->update(['uses_foreign_currency' => true]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'director_embg', 'uses_foreign_currency', 'payroll_obligation_code',
                'payroll_authorized_person', 'payroll_phone_prefix', 'payroll_phone',
                'payroll_mobile', 'payroll_municipality_code',
            ]);
        });
    }
};
