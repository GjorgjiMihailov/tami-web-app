<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses');
            $table->string('type', 20);
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 15, 4);
            $table->string('reason')->nullable();
            $table->date('movement_date');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['item_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
