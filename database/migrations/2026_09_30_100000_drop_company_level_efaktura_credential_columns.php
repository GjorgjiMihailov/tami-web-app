<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Токенот и е-УЈП ID-то се лични (на корисникот), не на фирмата — режимот
 * „токен на канцеларијата" никогаш не постоел кај УЈП. Старите колони беа
 * само резерва за компании регистрирани пред преработката; сопственикот
 * одлучи да се тргнат целосно наместо да останат мртви во базата.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('efaktura_firm_access_decided_by');
            $table->dropColumn([
                'efaktura_credential_mode', 'efaktura_eujp_id', 'efaktura_firm_access_status',
                'efaktura_firm_access_decided_at',
                'efaktura_token_serial_number', 'efaktura_token_subject_name',
                'efaktura_token_not_before', 'efaktura_token_not_after', 'efaktura_token_registered_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('efaktura_credential_mode')->default('firm')->after('invoice_footer_note');
            $table->string('efaktura_eujp_id')->nullable()->after('efaktura_credential_mode');
            $table->string('efaktura_firm_access_status')->default('none')->after('efaktura_eujp_id');
            $table->foreignId('efaktura_firm_access_decided_by')->nullable()->after('efaktura_firm_access_status')->constrained('users')->nullOnDelete();
            $table->timestamp('efaktura_firm_access_decided_at')->nullable()->after('efaktura_firm_access_decided_by');
            $table->string('efaktura_token_serial_number')->nullable()->after('efaktura_firm_access_decided_at');
            $table->string('efaktura_token_subject_name')->nullable()->after('efaktura_token_serial_number');
            $table->timestamp('efaktura_token_not_before')->nullable()->after('efaktura_token_subject_name');
            $table->timestamp('efaktura_token_not_after')->nullable()->after('efaktura_token_not_before');
            $table->timestamp('efaktura_token_registered_at')->nullable()->after('efaktura_token_not_after');
        });
    }
};
