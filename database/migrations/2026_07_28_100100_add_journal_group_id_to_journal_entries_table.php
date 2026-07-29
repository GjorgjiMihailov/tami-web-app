<?php

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('journal_group_id')->nullable()->after('company_id')->constrained();
        });

        // The new composite unique index is added BEFORE the old one is
        // dropped. MySQL/InnoDB requires the company_id foreign key
        // (added above) to always have a supporting index, and
        // (company_id, fiscal_year, entry_number) is currently the only
        // index covering it. Dropping it first throws "Cannot drop
        // index ...: needed in a foreign key constraint" under real
        // MySQL — SQLite never enforces this, which is why every local/
        // CI-SQLite test run passed despite this ordering bug (same
        // class of MySQL-only constraint this project has hit before,
        // see Phase 3b's identifier-length migration failure). Adding
        // the new unique first is safe even though journal_group_id is
        // still null for every row at this point: MySQL treats each
        // NULL in a unique index as distinct from every other NULL, so
        // no duplicate-key error is possible here regardless of how
        // many rows share the same (company_id, fiscal_year, NULL,
        // entry_number) tuple.
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unique(['company_id', 'fiscal_year', 'journal_group_id', 'entry_number']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'fiscal_year', 'entry_number']);
        });

        // Every existing entry predates journal groups — fold them all into
        // one auto-created "00 — Стари налози" group per company and
        // renumber sequentially per fiscal year within that group, so the
        // new unique index below never collides.
        Company::query()->get()->each(function (Company $company) {
            $legacyGroup = JournalGroup::create([
                'company_id' => $company->id,
                'code' => '00',
                'name' => 'Стари налози',
                'sort_order' => 0,
            ]);

            JournalEntry::where('company_id', $company->id)
                ->orderBy('fiscal_year')
                ->orderBy('entry_date')
                ->orderBy('id')
                ->get()
                ->groupBy('fiscal_year')
                ->each(function ($entriesInYear) use ($legacyGroup) {
                    $number = 0;
                    foreach ($entriesInYear as $entry) {
                        $number++;
                        $entry->forceFill([
                            'journal_group_id' => $legacyGroup->id,
                            'entry_number' => $number,
                        ])->save();
                    }
                });
        });
    }

    public function down(): void
    {
        // Intentional no-op: collapsing back would mean deleting the
        // auto-created "00" journal groups and reverting entry_number to
        // its pre-migration values, which this migration doesn't retain
        // anywhere. Matches this project's established precedent for lossy
        // backfill migrations (see the company_bank_accounts migration from
        // the Company Profile sub-project).
    }
};
