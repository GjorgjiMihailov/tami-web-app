<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            // Од наша гледна точка: позитивно = пари на сметка. Се внесуваат само
            // за контрола, никогаш не се книжат.
            $table->decimal('opening_balance', 15, 2)->nullable();
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->foreignId('journal_group_id')->nullable()->constrained('journal_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_group_id');
        });

        Schema::table('bank_statements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn(['opening_balance', 'closing_balance', 'status']);
        });
    }
};
