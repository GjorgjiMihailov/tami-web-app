<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('embg', 13);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('municipality_code', 16)->nullable();   // SifraOpstina
            $table->string('bank_account', 34);                    // TransakciskaSmetka
            $table->string('insurance_type_code', 16);             // SifraRabotenOdnos
            // broj_prijava — the М1/М2 registration/deregistration number for
            // compulsory social insurance. Not BrojDogovor, which is a
            // different МПИН field (the contract number).
            $table->string('registration_number', 32)->nullable();
            $table->date('employed_on');                           // DatumPocetok
            $table->date('terminated_on')->nullable();             // DatumZavrsuvanje
            $table->string('movement_code', 16)->nullable();       // SifraDvizenje
            $table->string('exemption_code', 16)->nullable();      // SifraOsloboduvanje
            $table->unsignedSmallInteger('weekly_hours')->default(40);
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();

            // Explicit short name — the generated one would be long enough to
            // risk MySQL's 64-character identifier limit.
            $table->unique(['company_id', 'embg'], 'employees_company_embg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
