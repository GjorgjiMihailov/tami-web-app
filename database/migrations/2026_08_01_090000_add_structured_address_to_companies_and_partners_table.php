<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('street_address')->nullable()->after('address');
            $table->string('street_number')->nullable()->after('street_address');
            $table->string('postal_code')->nullable()->after('street_number');
            $table->string('city')->nullable()->after('postal_code');
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->string('street_address')->nullable()->after('address');
            $table->string('street_number')->nullable()->after('street_address');
            $table->string('postal_code')->nullable()->after('street_number');
            $table->string('city')->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['street_address', 'street_number', 'postal_code', 'city']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['street_address', 'street_number', 'postal_code', 'city']);
        });
    }
};
