<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 24);
            $table->string('name');
            $table->timestamps();

            $table->unique(['company_id', 'doc_type'], 'posting_schemes_company_type_unique');
        });

        Schema::create('posting_scheme_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('posting_scheme_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('account_mode', 12);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('matrix_key', 24)->nullable();
            $table->string('side', 6);
            $table->string('formula');
            $table->boolean('with_partner')->default(false);
            $table->string('description')->nullable();
            $table->string('condition', 16)->nullable();
            $table->timestamps();
        });

        Schema::create('posting_scheme_matrix_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('posting_scheme_id')->constrained()->cascadeOnDelete();
            $table->string('matrix_key', 24);
            $table->string('item_kind', 8)->nullable();
            $table->string('vat_group', 24);
            $table->foreignId('account_id')->constrained('accounts');
            $table->timestamps();

            $table->unique(['posting_scheme_id', 'matrix_key', 'item_kind', 'vat_group'], 'psma_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_scheme_matrix_accounts');
        Schema::dropIfExists('posting_scheme_rows');
        Schema::dropIfExists('posting_schemes');
    }
};
