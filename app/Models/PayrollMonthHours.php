<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The month's fund of working hours. A national fact, not a per-company one:
 * the working days in July are the same for every client of the firm.
 *
 * Entered once a year by the admin rather than derived from a calendar. Two of
 * the Macedonian public holidays move with the Orthodox and Muslim calendars,
 * so a built-in calendar would be a maintenance obligation every December in
 * exchange for saving twelve numbers a year.
 */
class PayrollMonthHours extends Model
{
    use HasFactory;

    protected $table = 'payroll_month_hours';

    protected $fillable = ['year', 'month', 'hours'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'hours' => 'integer',
        ];
    }

    public static function forMonth(int $year, int $month): self
    {
        $fund = static::where('year', $year)->where('month', $month)->first();

        if ($fund === null) {
            throw new RuntimeException("Нема внесен фонд на часови за {$month}/{$year}.");
        }

        return $fund;
    }
}
