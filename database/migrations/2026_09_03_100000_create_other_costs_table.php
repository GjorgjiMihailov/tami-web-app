<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->date('cost_date');
            // Што е трошокот. Слободен текст зашто фискалните сметки не доаѓаат
            // во ниту еден шифрарник — човекот пишува што купил.
            $table->string('description');
            $table->decimal('amount', 15, 2);

            $table->foreignId('created_by')->constrained('users');

            $table->timestamps();

            // Списокот бара „трошоците на оваа фирма за оваа година".
            $table->index(['company_id', 'cost_date'], 'other_costs_company_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_costs');
    }
};
