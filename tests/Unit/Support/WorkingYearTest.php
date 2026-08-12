<?php

namespace Tests\Unit\Support;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkingYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_brand_new_company_offers_only_the_current_calendar_year(): void
    {
        $company = Company::factory()->create();

        $this->assertSame([(int) now()->year], WorkingYear::availableYears($company));
    }

    public function test_available_years_span_from_the_oldest_data_to_the_current_year_descending(): void
    {
        $company = Company::factory()->create();
        JournalEntry::factory()->for($company)->create(['entry_date' => '2024-03-04']);

        $expected = array_reverse(range(2024, (int) now()->year));

        $this->assertSame($expected, WorkingYear::availableYears($company));
    }

    public function test_it_defaults_to_the_current_calendar_year(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->assertSame((int) now()->year, WorkingYear::for($company));
    }

    public function test_it_remembers_a_separate_year_per_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->actingAs(User::factory()->create());

        WorkingYear::set($companyA, 2024);
        WorkingYear::set($companyB, 2023);

        $this->assertSame(2024, WorkingYear::for($companyA));
        $this->assertSame(2023, WorkingYear::for($companyB));
    }

    public function test_a_nonsense_stored_year_falls_back_to_the_current_year(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->create());

        WorkingYear::set($company, 1999);

        $this->assertSame((int) now()->year, WorkingYear::for($company));
    }

    public function test_the_year_boundaries_are_plain_date_strings(): void
    {
        $this->assertSame('2025-01-01', WorkingYear::startOf(2025));
        $this->assertSame('2025-12-31', WorkingYear::endOf(2025));
    }

    public function test_the_default_date_is_today_in_the_current_year_and_year_end_otherwise(): void
    {
        Carbon::setTestNow('2026-08-12');

        $this->assertSame('2026-08-12', WorkingYear::defaultDate(2026));
        $this->assertSame('2025-12-31', WorkingYear::defaultDate(2025));

        Carbon::setTestNow();
    }
}
