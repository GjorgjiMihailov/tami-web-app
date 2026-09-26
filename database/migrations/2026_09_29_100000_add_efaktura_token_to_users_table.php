<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Токенот е на ЧОВЕКОТ, не на фирмата: тој се овластува во е-УЈП за фирмите
        // за кои потпишува, а е-УЈП ID-то што оди во барањето е негово.
        Schema::table('users', function (Blueprint $table) {
            $table->string('efaktura_eujp_id', 100)->nullable();
            $table->string('efaktura_token_serial_number', 100)->nullable();
            $table->string('efaktura_token_subject_name')->nullable();
            $table->timestamp('efaktura_token_not_before')->nullable();
            $table->timestamp('efaktura_token_not_after')->nullable();
            $table->timestamp('efaktura_token_registered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'efaktura_eujp_id', 'efaktura_token_serial_number', 'efaktura_token_subject_name',
                'efaktura_token_not_before', 'efaktura_token_not_after', 'efaktura_token_registered_at',
            ]);
        });
    }
};
