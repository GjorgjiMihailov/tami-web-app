<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_codes', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('code', 16);
            $table->string('name');
            $table->timestamps();

            // Short explicit name: MySQL caps index identifiers at 64 chars and
            // the generated one would be close to the limit.
            $table->unique(['type', 'code'], 'payroll_codes_type_code_unique');
        });

        foreach (['opstina', 'vid_staz', 'sifra_dviz', 'osloboduvanje'] as $type) {
            $path = database_path('data/payroll-codes/'.$type.'.json');
            $rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            DB::table('payroll_codes')->insert(array_map(fn (array $row) => [
                'type' => $type,
                'code' => $row['code'],
                'name' => $row['name'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $rows));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_codes');
    }
};
