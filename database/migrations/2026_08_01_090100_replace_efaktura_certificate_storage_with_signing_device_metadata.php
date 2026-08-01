<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('efaktura_token_serial_number')->nullable()->after('efaktura_certificate_password');
            $table->string('efaktura_token_subject_name')->nullable()->after('efaktura_token_serial_number');
            $table->timestamp('efaktura_token_not_before')->nullable()->after('efaktura_token_subject_name');
            $table->timestamp('efaktura_token_not_after')->nullable()->after('efaktura_token_not_before');
            $table->timestamp('efaktura_token_registered_at')->nullable()->after('efaktura_token_not_after');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['efaktura_certificate_path', 'efaktura_certificate_password']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('efaktura_certificate_path')->nullable()->after('efaktura_eujp_id');
            $table->text('efaktura_certificate_password')->nullable()->after('efaktura_certificate_path');
            $table->dropColumn([
                'efaktura_token_serial_number', 'efaktura_token_subject_name',
                'efaktura_token_not_before', 'efaktura_token_not_after', 'efaktura_token_registered_at',
            ]);
        });
    }
};
