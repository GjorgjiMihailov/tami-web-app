<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\PostingScheme;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpgradeDefaultSalesSchemesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        (require base_path('database/migrations/2026_10_08_120000_upgrade_default_sales_schemes_for_import_cogs.php'))->up();
    }

    /** Шема како што беше пред промената: 6600 Побарува НАБАВНА_ВРЕДНОСТ, без ред за 6601. */
    private function oldDefault(Company $company): PostingScheme
    {
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $scheme->rows()->where('formula', 'НАБАВНА_УВОЗ')->delete();
        $scheme->rows()->where('formula', 'НАБАВНА_ДОМАШНА')->update(['formula' => 'НАБАВНА_ВРЕДНОСТ']);

        return $scheme->fresh();
    }

    public function test_an_untouched_old_default_scheme_is_upgraded(): void
    {
        $company = Company::factory()->create();
        $scheme = $this->oldDefault($company);
        $this->assertSame(5, $scheme->rows()->count());

        $this->runMigration();

        $rows = $scheme->fresh()->rows()->with('account')->get();
        $this->assertCount(6, $rows);
        $this->assertSame(['6600' => 'НАБАВНА_ДОМАШНА', '6601' => 'НАБАВНА_УВОЗ'], $rows->whereIn('account.code', ['6600', '6601'])->pluck('formula', 'account.code')->all());
        $this->assertSame(6, $rows->last()->position);
    }

    public function test_a_scheme_changed_by_an_accountant_is_left_alone(): void
    {
        $company = Company::factory()->create();
        $scheme = $this->oldDefault($company);
        $scheme->rows()->first()->update(['description' => 'Мој опис']);

        $this->runMigration();

        $this->assertSame(5, $scheme->fresh()->rows()->count());
        $this->assertSame('НАБАВНА_ВРЕДНОСТ', $scheme->fresh()->rows()->where('position', 5)->first()->formula);
    }

    public function test_an_already_upgraded_scheme_is_not_touched_again(): void
    {
        $company = Company::factory()->create();
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);

        $this->runMigration();

        $this->assertSame(6, $scheme->fresh()->rows()->count());
    }
}
