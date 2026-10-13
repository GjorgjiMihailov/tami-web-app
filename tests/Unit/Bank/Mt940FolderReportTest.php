<?php

namespace Tests\Unit\Bank;

use App\Support\Bank\Mt940\Mt940FolderReport;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class Mt940FolderReportTest extends TestCase
{
    /** @return list<array<string, string>> */
    private function in(string $amount): array
    {
        return [['dir' => 'in', 'amount' => $amount]];
    }

    public function test_a_clean_run_reports_no_problems(): void
    {
        $report = Mt940FolderReport::analyse([
            'a.txt' => Mt940Fixture::statement(['number' => 1, 'date' => '2026-02-02', 'opening' => '0.00', 'lines' => $this->in('100.00')]),
            'b.txt' => Mt940Fixture::statement(['number' => 2, 'date' => '2026-02-11', 'opening' => '100.00', 'lines' => $this->in('50.00')]),
        ]);

        $this->assertSame(2, $report['files']);
        $this->assertSame(2, $report['statements']);
        $this->assertSame(2, $report['lines']);
        $this->assertSame([], $report['refused']);
        $this->assertSame([], $report['duplicates']);

        $account = $report['accounts'][Mt940Fixture::ACCOUNT.'/2026'];
        $this->assertSame(1, $account['first']);
        $this->assertSame(2, $account['last']);
        $this->assertSame([], $account['gaps']);
        $this->assertSame([], $account['chain_breaks']);
        $this->assertSame([], $account['mismatches']);
    }

    public function test_a_missing_number_is_reported_and_is_not_also_a_chain_break(): void
    {
        $report = Mt940FolderReport::analyse([
            'a.txt' => Mt940Fixture::statement(['number' => 1, 'opening' => '0.00', 'lines' => $this->in('100.00')]),
            'c.txt' => Mt940Fixture::statement(['number' => 3, 'date' => '2026-02-12', 'opening' => '150.00', 'lines' => $this->in('10.00')]),
        ]);

        $account = $report['accounts'][Mt940Fixture::ACCOUNT.'/2026'];
        $this->assertSame(['фали 2'], $account['gaps']);
        $this->assertSame([], $account['chain_breaks']);
    }

    public function test_a_chain_break_and_a_mismatch_are_reported(): void
    {
        $report = Mt940FolderReport::analyse([
            'a.txt' => Mt940Fixture::statement(['number' => 1, 'opening' => '0.00', 'lines' => $this->in('100.00')]),
            'b.txt' => Mt940Fixture::statement(['number' => 2, 'date' => '2026-02-11', 'opening' => '90.00', 'closing' => '999.00', 'lines' => $this->in('10.00')]),
        ]);

        $account = $report['accounts'][Mt940Fixture::ACCOUNT.'/2026'];
        $this->assertCount(1, $account['chain_breaks']);
        $this->assertCount(1, $account['mismatches']);
    }

    public function test_a_refused_file_and_a_duplicate_number_are_listed(): void
    {
        $report = Mt940FolderReport::analyse([
            'a.txt' => Mt940Fixture::statement(['number' => 1]),
            'a-copy.txt' => Mt940Fixture::statement(['number' => 1]),
            'eur.txt' => Mt940Fixture::statement(['number' => 2, 'currency' => 'EUR']),
        ]);

        $this->assertSame('eur.txt', $report['refused'][0]['file']);
        $this->assertStringContainsString('MKD', $report['refused'][0]['reason']);
        $this->assertCount(1, $report['duplicates']);
        $this->assertSame(1, $report['statements']);
    }
}
