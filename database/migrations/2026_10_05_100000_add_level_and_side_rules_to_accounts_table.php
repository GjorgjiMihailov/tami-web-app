<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // class | group | subgroup | account — the level in the official chart.
            $table->string('level', 10)->default('account')->after('name');
            // з.д. / з.п.: the account may carry an amount only on the debit
            // (must_debit) or only on the credit (must_credit) side.
            $table->boolean('must_debit')->default(false)->after('is_analytical');
            $table->boolean('must_credit')->default(false)->after('must_debit');
        });

        // Today's rows are 3-digit official accounts (subgroups) and the
        // analytical accounts the accountants added themselves (4+ digits).
        DB::table('accounts')->whereRaw('LENGTH(code) = 3')->update(['level' => 'subgroup']);
        DB::table('accounts')->whereRaw('LENGTH(code) = 2')->update(['level' => 'group']);
        DB::table('accounts')->whereRaw('LENGTH(code) = 1')->update(['level' => 'class']);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['level', 'must_debit', 'must_credit']);
        });
    }
};
