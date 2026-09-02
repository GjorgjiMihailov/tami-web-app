<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('bank');
            $table->string('account');
            $table->string('kind', 16);

            // Бројот постои само за да се види дека нешто фали. Броењето почнува
            // од почеток секоја година и е одделно за секоја сметка, па низата
            // се чита по сметка и по година, не низ целата табела.
            $table->unsignedInteger('number');
            $table->date('statement_date');

            $table->foreignId('uploaded_by')->constrained('users');

            $table->timestamps();

            // Списокот бара „изводите на оваа фирма, по сметка и по број".
            $table->index(['company_id', 'account', 'number'], 'bank_statements_company_seq_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }
};
