<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->decimal('quantity_on_hand', 15, 3)->default(0);
            $table->decimal('average_cost', 15, 4)->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
