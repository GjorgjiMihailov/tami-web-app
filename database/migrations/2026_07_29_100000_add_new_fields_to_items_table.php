<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('selling_price', 12, 2)->nullable()->after('vat_rate');
            $table->string('type', 20)->default('product')->after('selling_price');
            $table->boolean('is_made_in_mk')->default(false)->after('type');
            $table->string('barcode', 50)->nullable()->after('is_made_in_mk');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->unique(['company_id', 'barcode'], 'items_company_barcode_unique');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique('items_company_barcode_unique');
            $table->dropColumn(['selling_price', 'type', 'is_made_in_mk', 'barcode']);
        });
    }
};
