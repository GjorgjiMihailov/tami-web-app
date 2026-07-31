<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('efaktura_credential_mode')->default('firm')->after('invoice_footer_note');
            $table->string('efaktura_eujp_id')->nullable()->after('efaktura_credential_mode');
            $table->text('efaktura_certificate_path')->nullable()->after('efaktura_eujp_id');
            $table->text('efaktura_certificate_password')->nullable()->after('efaktura_certificate_path');
            $table->string('efaktura_firm_access_status')->default('none')->after('efaktura_certificate_password');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'efaktura_credential_mode', 'efaktura_eujp_id', 'efaktura_certificate_path',
                'efaktura_certificate_password', 'efaktura_firm_access_status',
            ]);
        });
    }
};
