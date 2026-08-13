<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_parameters', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->decimal('rate_pension', 6, 3);
            $table->decimal('rate_health', 6, 3);
            $table->decimal('rate_injury', 6, 3);
            $table->decimal('rate_unemployment', 6, 3);
            $table->decimal('rate_tax', 6, 3);
            $table->decimal('personal_allowance', 12, 2);
            $table->decimal('average_salary', 12, 2);
            $table->decimal('min_base', 12, 2);
            $table->decimal('max_base', 12, 2);
            $table->decimal('minimum_wage', 12, 2);
            $table->timestamps();
        });

        $shared = [
            'rate_health' => 7.5,
            'rate_injury' => 0.5,
            'rate_tax' => 10,
            'personal_allowance' => 10932,
            'average_salary' => 69141,
            'min_base' => 34571,
            'max_base' => 1106256,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('payroll_parameters')->insert([
            // Rates unchanged Jan–Jun; only the minimum wage moves on 1 March.
            ['effective_from' => '2026-01-01', 'rate_pension' => 18.8, 'rate_unemployment' => 1.2, 'minimum_wage' => 36037] + $shared,
            ['effective_from' => '2026-03-01', 'rate_pension' => 18.8, 'rate_unemployment' => 1.2, 'minimum_wage' => 38507] + $shared,
            // User-confirmed: the new rates apply from the JULY salary. The draft
            // law's "започнувајќи со исплатата на платата за месец јуни" does not
            // refer to the month being calculated for.
            ['effective_from' => '2026-07-01', 'rate_pension' => 19.9, 'rate_unemployment' => 0.1, 'minimum_wage' => 38507] + $shared,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_parameters');
    }
};
