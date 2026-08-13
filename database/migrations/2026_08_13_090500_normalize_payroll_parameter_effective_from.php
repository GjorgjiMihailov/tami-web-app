<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Strips any time component left in payroll_parameters.effective_from.
     *
     * Periods added through the admin screen before PayrollParameter got its
     * set mutator were stored as '2027-01-01 00:00:00' on SQLite, while the
     * seeding migration's raw insert wrote '2027-01-01'. The unique validation
     * rule compares a plain date, so those rows were invisible to it. The
     * mutator stops new ones appearing; this brings existing ones into line.
     *
     * Done row by row in PHP rather than with a database date function, which
     * differs between the SQLite used locally and the MySQL used in CI and
     * production. On MySQL the DATE column already reads back plain, so every
     * row here is a no-op.
     */
    public function up(): void
    {
        foreach (DB::table('payroll_parameters')->get(['id', 'effective_from']) as $row) {
            $stored = (string) $row->effective_from;
            $plain = substr($stored, 0, 10);

            if ($plain !== $stored) {
                DB::table('payroll_parameters')
                    ->where('id', $row->id)
                    ->update(['effective_from' => $plain]);
            }
        }
    }

    public function down(): void
    {
        // Putting the meaningless '00:00:00' back would serve nobody.
    }
};
