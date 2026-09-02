<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form743s', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('status', 16)->default('pending');

            // Полињата од самиот образец. Nullable зашто клиентот качува само
            // фајл — ги пополнува канцеларијата во моментот кога ја внесува
            // пријавата во УЈП, што е истото читање што и онака го прави.
            $table->string('payer')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('payment_date')->nullable();
            $table->string('basis')->nullable();

            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('filed_by')->nullable()->constrained('users');
            $table->timestamp('filed_at')->nullable();

            $table->timestamps();

            // Работниот список бара „сите необработени, најстарите прво", низ
            // сите клиенти — затоа status води во индексот, не company_id.
            $table->index(['status', 'created_at'], 'form743s_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form743s');
    }
};
