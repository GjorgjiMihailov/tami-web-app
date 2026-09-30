<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Иста логика како proforma_number_prefix: годината, разделникот и
            // должината на бројот се исти како кај фактурата, само префиксот е свој.
            $table->string('delivery_note_number_prefix', 10)->nullable()->default('ИСП-')->after('proforma_number_prefix');
        });

        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('deliverable_type');
            $table->unsignedBigInteger('deliverable_id');
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('delivery_note_number');
            $table->string('delivery_note_number_formatted', 40);
            $table->date('delivery_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year', 'delivery_note_number']);
            // Спречува втора испратница за истиот извор — секое второ барање
            // мора да ја врати постојната наместо да создаде нова.
            $table->unique(['deliverable_type', 'deliverable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('delivery_note_number_prefix');
        });
    }
};
