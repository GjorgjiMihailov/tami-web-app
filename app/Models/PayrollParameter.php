<?php

namespace App\Models;

use Carbon\Carbon;
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
