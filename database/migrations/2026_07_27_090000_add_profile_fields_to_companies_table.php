<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('short_name')->nullable()->after('name');
            $table->string('registration_number')->nullable()->after('tax_id');
            $table->string('nkd_code')->nullable()->after('registration_number');
            $table->string('nkd_name')->nullable()->after('nkd_code');
            $table->string('website')->nullable()->after('address');
            $table->string('director_name')->nullable()->after('website');
            $table->string('director_phone')->nullable()->after('director_name');
            $table->string('director_email')->nullable()->after('director_phone');
            $table->string('logo_position')->default('left')->after('logo_path');
            $table->text('invoice_footer_note')->nullable()->after('is_vat_registered');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'short_name',
                'registration_number',
                'nkd_code',
                'nkd_name',
                'website',
                'director_name',
                'director_phone',
                'director_email',
                'logo_position',
                'invoice_footer_note',
            ]);
        });
    }
};
