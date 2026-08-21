<?php

namespace App\Models;

use App\Support\Payroll\MonthCoverage;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'embg', 'first_name', 'last_name', 'municipality_code',
        'health_area_code',
        'bank_account', 'insurance_type_code', 'registration_number',
        'employed_on', 'terminated_on', 'movement_code', 'exemption_code',
        'weekly_hours', 'prior_service_months', 'address', 'phone', 'email',
    ];

    protected function casts(): array
    {
        return [
            'employed_on' => 'date',
            'terminated_on' => 'date',
            'weekly_hours' => 'integer',
            'prior_service_months' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class)->orderByDesc('effective_from');
    }

    /** The agreed salary in force on the given date, or null if none was yet agreed. */
    public function salaryOn(string $date): ?EmployeeSalary
    {
        return $this->salaries()
            ->where('effective_from', '<=', Carbon::parse($date)->endOfDay())
            ->orderByDesc('effective_from')
            ->first();
    }

    public function isActiveOn(string $date): bool
    {
        if ($this->employed_on->toDateString() > $date) {
            return false;
        }

        return $this->terminated_on === null || $this->terminated_on->toDateString() >= $date;
    }

    /**
     * How much of the given month this employment covers.
     *
     * Note this is a different question from isActiveOn(), which asks about one
     * instant and is what the employee list uses to decide who is here today. A
     * payroll month wants the overlap instead: someone who left on the 10th is
     * not active on the 31st, but is owed ten days of pay.
     */
    public function coverageIn(int $year, int $month): MonthCoverage
    {
        return MonthCoverage::for(
            $this->employed_on->toDateString(),
            $this->terminated_on?->toDateString(),
            $year,
            $month,
        );
    }

    /**
     * Месечниот фонд часови сведен на договореното работно време.
     *
     * Полно работно време е 40 часа неделно, па за таков работник ова го враќа
     * фондот непроменет и ниту една постоечка пресметка не се поместува. За
     * неполно работно време и делителот на часовната стапка и бројот часови се
     * сведуваат со ист множител, така што договорената бруто плата останува иста
     * — се менува само бројот часови, кој е она што МПИН го пријавува и што мора
     * да се согласува со шифрата за вид на стаж.
     */
    public function monthFund(int $runFund): int
    {
        return (int) round($runFund * $this->weekly_hours / 40);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * Completed years of total service on the given date: time here plus the
     * service brought from previous employers.
     *
     * Completed years, not fractional ones — минат труд steps up on the
     * anniversary, it does not accrue daily. Uses an exact calendar diff
     * rather than diffInMonths(), which returns a float derived from an
     * average days-per-month constant and would only happen to truncate to
     * the right whole month by luck.
     */
    public function seniorityYearsOn(string $date): int
    {
        $on = Carbon::parse($date);

        if ($this->employed_on->gt($on)) {
            return 0;
        }

        $diff = $this->employed_on->diff($on);
        $months = $diff->y * 12 + $diff->m + $this->prior_service_months;

        return intdiv($months, 12);
    }
}
