<?php

namespace App\Models;

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
        'bank_account', 'insurance_type_code', 'registration_number',
        'employed_on', 'terminated_on', 'movement_code', 'exemption_code',
        'weekly_hours', 'address', 'phone', 'email',
    ];

    protected function casts(): array
    {
        return [
            'employed_on' => 'date',
            'terminated_on' => 'date',
            'weekly_hours' => 'integer',
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

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
