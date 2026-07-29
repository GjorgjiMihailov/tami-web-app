<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JournalGroupBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_backfill_migration_renumbers_legacy_entries_in_entry_date_order_even_when_original_entry_numbers_were_out_of_sync(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        // Roll the schema back to its pre-migration shape (this migration is
        // part of the baseline, so by the time this test runs the target
        // schema -- journal_group_id column + the new 4-column unique index
        // -- already exists). Drop the new unique index and the column, and
        // restore the original 3-column unique index the migration itself
        // drops in up(), matching this project's established pattern for
        // testing a data-backfill migration that's already baked into the
        // schema baseline (see CompanyBankAccountMigrationTest).
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'fiscal_year', 'journal_group_id', 'entry_number']);
            $table->dropConstrainedForeignId('journal_group_id');
        });
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unique(['company_id', 'fiscal_year', 'entry_number']);
        });

        // Seed three legacy entries whose entry_number order deliberately does
        // NOT match their entry_date order -- exactly the scenario that would
        // have caught the entry_number-silently-dropped-by-mass-assignment
        // bug: if the migration's renumbering were a no-op (the bug), these
        // entries would keep their original, out-of-sync numbers instead of
        // being resequenced 1, 2, 3 in entry_date order.
        DB::table('journal_entries')->insert([
            [
                'company_id' => $company->id,
                'fiscal_year' => 2026,
                'entry_date' => '2026-03-10',
                'entry_number' => 5,
                'description' => 'Legacy entry dated last, originally numbered first',
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $company->id,
                'fiscal_year' => 2026,
                'entry_date' => '2026-01-05',
                'entry_number' => 2,
                'description' => 'Legacy entry dated first',
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $company->id,
                'fiscal_year' => 2026,
                'entry_date' => '2026-02-20',
                'entry_number' => 9,
                'description' => 'Legacy entry dated middle, originally numbered last',
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // The migration's own up() re-adds journal_group_id and the new
        // unique index at the end, so no manual schema restoration is needed
        // after this call.
        (require database_path('migrations/2026_07_28_100100_add_journal_group_id_to_journal_entries_table.php'))->up();

        $legacyGroup = JournalGroup::where('company_id', $company->id)->where('code', '00')->firstOrFail();
        $this->assertSame('Стари налози', $legacyGroup->name);

        $entriesByDate = JournalEntry::where('company_id', $company->id)
            ->orderBy('entry_date')
            ->get();

        $this->assertCount(3, $entriesByDate);
        $this->assertTrue($entriesByDate->every(fn ($entry) => $entry->journal_group_id === $legacyGroup->id));

        // Renumbered 1, 2, 3 in entry_date order -- NOT the original 2, 9, 5.
        $this->assertSame(1, $entriesByDate[0]->entry_number);
        $this->assertSame(2, $entriesByDate[1]->entry_number);
        $this->assertSame(3, $entriesByDate[2]->entry_number);
    }
}
