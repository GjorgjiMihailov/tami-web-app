<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            // account_number останува како што е — кај дел од клиентите таму
            // веќе стои IBAN. Ова поле е за случајот кога двете се разликуваат.
            $table->string('iban')->nullable()->after('account_number');
            $table->string('swift', 20)->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('company_bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['iban', 'swift']);
        });
    }
};
