<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRunEmployee extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'gross', 'pension', 'health', 'injury',
        'unemployment', 'contributions', 'tax_base', 'tax', 'net',
        'deductions_total', 'effective_net', 'top_up_pension', 'top_up_health',
        'top_up_injury', 'top_up_unemployment', 'top_up', 'hourly_rate',
        'seniority_years', 'full_month_gross',
        'employer_gross', 'employer_contributions', 'employer_tax', 'employer_net',
        'staz_days',
    ];

    protected function casts(): array
    {
        return [
            'gross' => 'float', 'pension' => 'float', 'health' => 'float',
            'injury' => 'float', 'unemployment' => 'float', 'contributions' => 'float',
            'tax_base' => 'float', 'tax' => 'float', 'net' => 'float',
            'deductions_total' => 'float', 'effective_net' => 'float',
            'top_up_pension' => 'float', 'top_up_health' => 'float',
            'top_up_injury' => 'float', 'top_up_unemployment' => 'float',
            'top_up' => 'float', 'hourly_rate' => 'float',
            'full_month_gross' => 'float', 'seniority_years' => 'integer',
            'employer_gross' => 'float', 'employer_contributions' => 'float',
            'employer_tax' => 'float', 'employer_net' => 'float',
            'staz_days' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class, 'payroll_run_employee_id')->orderBy('id');
    }
}
