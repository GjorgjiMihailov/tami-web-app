<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRunLine extends Model
{
    public const KIND_HOURS = 'hours';

    public const KIND_AMOUNT = 'amount';

    public const KIND_DEDUCTION = 'deduction';

    public const BORNE_EMPLOYER = 'employer';

    public const BORNE_FZO = 'fzo';

    protected $fillable = [
        'payroll_run_employee_id', 'kind', 'code', 'description',
        'hours', 'percent', 'amount', 'borne_by', 'is_automatic',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'integer',
            'percent' => 'float',
            'amount' => 'float',
            'is_automatic' => 'boolean',
        ];
    }

    public function runEmployee(): BelongsTo
    {
        return $this->belongsTo(PayrollRunEmployee::class, 'payroll_run_employee_id');
    }
}
