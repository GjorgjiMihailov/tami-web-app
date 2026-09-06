<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Своја табела, не `password_reset_tokens`: рокот е различен (7 дена
        // наспроти 60 минути), а споделена табела клучена по е-пошта значи
        // дека покана и барање за нова лозинка се газат меѓусебно.
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Се чува само отпечаток. Затоа линкот се гледа само еднаш, веднаш
            // по создавањето — подоцна се издава нов.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
