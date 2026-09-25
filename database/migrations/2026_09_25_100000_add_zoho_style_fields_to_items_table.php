<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->decimal('cost_price', 12, 2)->nullable()->after('selling_price');
            // null = набавката го користи истиот ДДВ како продажбата (vat_rate).
            $table->decimal('purchase_vat_rate', 5, 2)->nullable()->after('vat_rate');
            $table->boolean('is_sellable')->default(true)->after('is_active');
            $table->boolean('is_purchasable')->default(true)->after('is_sellable');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['description', 'cost_price', 'purchase_vat_rate', 'is_sellable', 'is_purchasable']);
        });
    }
};
