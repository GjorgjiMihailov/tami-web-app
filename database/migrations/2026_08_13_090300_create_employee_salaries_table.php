<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->decimal('amount', 12, 2);
            // Only the agreed side is stored. Keeping both gross and net would
            // let them drift apart the moment a rate changes.
            $table->string('basis', 8);
            $table->timestamps();

            $table->index(['employee_id', 'effective_from'], 'employee_salaries_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salaries');
    }
};
