<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_posting_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 24);
            $table->string('name');
            $table->json('definition');
            $table->timestamps();

            $table->unique(['user_id', 'doc_type'], 'upsch_user_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_posting_schemes');
    }
};
