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
                        $entry->update([
                            'journal_group_id' => $legacyGroup->id,
                            'entry_number' => $number,
                        ]);
                    }
                });
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unique(['company_id', 'fiscal_year', 'journal_group_id', 'entry_number']);
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
