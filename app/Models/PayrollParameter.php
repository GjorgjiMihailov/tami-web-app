<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PayrollParameter extends Model
{
    protected $fillable = [
        'effective_from', 'rate_pension', 'rate_health', 'rate_injury',
        'rate_unemployment', 'rate_tax', 'personal_allowance',
        'average_salary', 'min_base', 'max_base', 'minimum_wage',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'rate_pension' => 'float',
            'rate_health' => 'float',
            'rate_injury' => 'float',
            'rate_unemployment' => 'float',
            'rate_tax' => 'float',
            'personal_allowance' => 'float',
            'average_salary' => 'float',
            'min_base' => 'float',
            'max_base' => 'float',
            'minimum_wage' => 'float',
        ];
    }

    /**
     * Written as a plain date, on purpose.
     *
     * With only the `date` cast, Eloquent serialises through getDateFormat()
     * ('Y-m-d H:i:s'), so SQLite would store '2027-01-01 00:00:00' for a period
     * added through the screen while the migration's raw insert stores
     * '2027-01-01'. Two storage shapes in one column is what breaks
     * `unique:payroll_parameters,effective_from`: the rule builds a plain
     * where() and Eloquent applies no casts there, so it would miss every
     * period the screen created, pass validation, and let the request hit the
     * column's unique index — a 500 instead of the Macedonian message.
     *
     * A set mutator runs before the date cast on write, so this makes both
     * creation paths agree. On MySQL the DATE column truncates the time anyway;
     * this only brings SQLite into line with it.
     */
    protected function effectiveFrom(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }

    /**
     * The parameter set in force on the given date: the newest period that
     * started on or before it.
     */
    public static function forDate(string $date): self
    {
        $parameter = static::where('effective_from', '<=', Carbon::parse($date)->endOfDay())
            ->orderByDesc('effective_from')
            ->first();

        if ($parameter === null) {
            throw new RuntimeException("Нема параметри за пресметка што важат на {$date}.");
        }

        return $parameter;
    }
}
