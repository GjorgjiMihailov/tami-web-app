<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->unsignedTinyInteger('staz_days')->default(0);
        });

        // Existing runs each gave every employee a full month, because that is
        // all the old code could do — so a full month is also what they paid and
        // posted, and backfilling anything else would contradict a confirmed
        // journal entry. Drafts correct themselves on the next recalculation.
        //
        // Done in PHP rather than one UPDATE ... JOIN: that statement is written
        // differently in SQLite and MySQL, and this project runs both.
        DB::table('payroll_runs')->orderBy('id')->chunk(200, function ($runs) {
            foreach ($runs as $run) {
                DB::table('payroll_run_employees')
                    ->where('payroll_run_id', $run->id)
                    ->update([
                        'staz_days' => CarbonImmutable::create($run->year, $run->month, 1)->daysInMonth,
                    ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->dropColumn('staz_days');
        });
    }
};
