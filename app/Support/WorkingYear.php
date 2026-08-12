<?php

namespace App\Support;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Warehouse;

class WorkingYear
{
    // A sanity bound, not the selector's option list. It only exists so a stale
    // or hand-edited session value can never produce a nonsense date filter.
    private const MIN_YEAR = 2000;

    public static function sessionKey(int $userId, int $companyId): string
    {
        return "working_year.{$userId}.{$companyId}";
    }

    public static function for(Company $company): int
    {
        $stored = session(self::sessionKey((int) auth()->id(), $company->id));

        if (is_int($stored) && $stored >= self::MIN_YEAR && $stored <= (int) now()->year + 1) {
            return $stored;
        }

        return (int) now()->year;
    }

    public static function set(Company $company, int $year): void
    {
        session([self::sessionKey((int) auth()->id(), $company->id) => $year]);
    }

    /**
     * Every year the company could reasonably be working in: the span from its
     * earliest to its latest document, widened to include the current calendar
     * year. Newest first.
     *
     * Deliberately built from min/max rather than DISTINCT YEAR(...), which
     * would need a database date function — those differ between the SQLite
     * used locally and the MySQL used in CI and production.
     *
     * @return list<int>
     */
    public static function availableYears(Company $company): array
    {
        $warehouseIds = Warehouse::where('company_id', $company->id)->select('id');

        // Each entry pairs a company-scoped query with the date column to read.
        $queries = [
            [JournalEntry::where('company_id', $company->id), 'entry_date'],
            [SalesInvoice::where('company_id', $company->id), 'invoice_date'],
            [PurchaseInvoice::where('company_id', $company->id), 'invoice_date'],
            [StockMovement::whereIn('warehouse_id', $warehouseIds), 'movement_date'],
        ];

        $years = [(int) now()->year];

        foreach ($queries as [$query, $column]) {
            $min = (clone $query)->min($column);
            $max = (clone $query)->max($column);

            if ($min === null || $max === null) {
                continue;
            }

            $years[] = (int) substr((string) $min, 0, 4);
            $years[] = (int) substr((string) $max, 0, 4);
        }

        return array_reverse(range(min($years), max($years)));
    }

    public static function startOf(int $year): string
    {
        return sprintf('%04d-01-01', $year);
    }

    public static function endOf(int $year): string
    {
        return sprintf('%04d-12-31', $year);
    }

    /**
     * The date a brand-new record should open with: today when you are working
     * in the current year, otherwise the last day of the year you are in.
     */
    public static function defaultDate(int $year): string
    {
        return $year === (int) now()->year
            ? now()->toDateString()
            : self::endOf($year);
    }
}
