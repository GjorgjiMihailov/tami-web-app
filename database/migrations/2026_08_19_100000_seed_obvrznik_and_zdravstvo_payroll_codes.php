<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPES = ['vid_obvrznik', 'podracno_zdravstvo'];

    public function up(): void
    {
        foreach (self::TYPES as $type) {
            $path = database_path("data/payroll-codes/{$type}.json");
            $codes = json_decode(file_get_contents($path), true);

            $rows = array_map(fn (array $c) => [
                'type' => $type,
                'code' => $c['code'],
                'name' => $c['name'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $codes);

            // Chunked because MySQL's max_allowed_packet is the one thing that
            // makes a multi-row insert fail in CI but not locally.
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('payroll_codes')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        DB::table('payroll_codes')->whereIn('type', self::TYPES)->delete();
    }
};
