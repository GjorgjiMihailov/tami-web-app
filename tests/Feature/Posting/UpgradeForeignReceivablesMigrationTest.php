<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\PostingScheme;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpgradeForeignReceivablesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        (require base_path('database/migrations/2026_10_28_110000_upgrade_default_sales_scheme_for_foreign_receivables.php'))->up();
    }

    /** Шема како што беше пред промената: само 1200 без услов, без редови за 1210/1211. */
    private function oldDefault(Company $company): PostingScheme
    {
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $scheme->rows()->whereIn('formula', ['ВКУПНО_ДОБРА', 'ВКУПНО_УСЛУГИ'])->delete();
        $scheme->rows()->where('formula', 'ВКУПНО')->update(['condition' => null]);

        foreach ($scheme->rows()->orderBy('position')->get() as $i => $row) {
            $row->update(['position' => $i + 1]);
        }

        return $scheme->fresh();
    }

    private function signature(PostingScheme $scheme): array
    {
        return $scheme->fresh()->rows()->orderBy('position')->get()
            ->map(fn ($r) => [$r->position, $r->formula, $r->side, $r->condition])->all();
    }

    public function test_an_untouched_default_scheme_gets_the_foreign_rows(): void
    {
        $scheme = $this->oldDefault(Company::factory()->create());
        $this->assertSame(6, $scheme->rows()->count());

        $this->runMigration();

        $rows = $scheme->fresh()->rows()->orderBy('position')->with('account')->get();
        $this->assertCount(8, $rows);
        $this->assertSame(['1200', 'ВКУПНО', 'not_foreign'], [$rows[0]->account->code, $rows[0]->formula, $rows[0]->condition]);
        $this->assertSame(['1210', 'ВКУПНО_ДОБРА', 'foreign'], [$rows[1]->account->code, $rows[1]->formula, $rows[1]->condition]);
        $this->assertSame(['1211', 'ВКУПНО_УСЛУГИ', 'foreign'], [$rows[2]->account->code, $rows[2]->formula, $rows[2]->condition]);
    }

    public function test_a_scheme_edited_by_an_accountant_is_left_alone(): void
    {
        $scheme = $this->oldDefault(Company::factory()->create());
        $scheme->rows()->where('formula', 'ОСНОВИЦА')->update(['description' => 'моја опис']);
        $before = $this->signature($scheme);

        $this->runMigration();

        $this->assertSame($before, $this->signature($scheme));
    }

    public function test_running_the_migration_twice_adds_the_rows_once(): void
    {
        $scheme = $this->oldDefault(Company::factory()->create());

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(8, $scheme->fresh()->rows()->count());
    }
}
