<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('type')->default('legal_entity')->after('name');
            $table->string('registration_number')->nullable()->after('address');
            $table->string('director_name')->nullable()->after('registration_number');
            $table->boolean('is_vat_registered')->default(false)->after('director_name');
            $table->string('vat_number')->nullable()->after('is_vat_registered');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'registration_number',
                'director_name',
                'is_vat_registered',
                'vat_number',
            ]);
        });
    }
};
