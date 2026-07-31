<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('efaktura_firm_access_decided_by')->nullable()->after('efaktura_firm_access_status')->constrained('users')->nullOnDelete();
            $table->timestamp('efaktura_firm_access_decided_at')->nullable()->after('efaktura_firm_access_decided_by');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('efaktura_firm_access_decided_by');
            $table->dropColumn('efaktura_firm_access_decided_at');
        });
    }
};
