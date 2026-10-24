<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppf_payees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('surname');
            $table->string('embg', 13);
            // payroll_codes, тип opstina.
            $table->string('municipality_code', 16);
            $table->string('bank_account', 50)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'embg'], 'ppf_payee_company_embg_unique');
        });

        Schema::create('ppf_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ppf_payee_id')->constrained('ppf_payees')->cascadeOnDelete();
            // PpfCodes: вид (T1…, или стар 1–19) и подвид.
            $table->string('type_income_code', 8);
            $table->string('subtype_income_code', 8)->nullable();
            // Цели денари: XSD бара nonNegativeInteger.
            $table->unsignedBigInteger('gross_income');
            $table->date('payment_date');
            $table->string('payment_type_code', 8)->default('00001');
            // Изборни полиња — во ниеден вистински фајл не се користени.
            $table->unsignedBigInteger('advance')->nullable();
            $table->unsignedBigInteger('vat_amount')->nullable();
            $table->unsignedBigInteger('gross_free')->nullable();
            $table->string('decision_number', 64)->nullable();
            $table->date('decision_date')->nullable();
            $table->string('comment')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'payment_date'], 'ppf_payment_company_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppf_payments');
        Schema::dropIfExists('ppf_payees');
    }
};
