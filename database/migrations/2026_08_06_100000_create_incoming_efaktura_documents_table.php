<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_efaktura_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('euid');
            $table->string('status_code', 10)->nullable();
            $table->string('status_name')->nullable();
            $table->string('doc_number')->nullable();
            $table->date('doc_date')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('seller_tax_id')->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->json('payload_json');
            $table->timestamp('discovered_at');
            $table->string('decision', 20)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->string('reject_reason_code', 10)->nullable();
            $table->string('reject_comment')->nullable();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained();
            $table->string('efaktura_pdf_path')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'euid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_efaktura_documents');
    }
};
