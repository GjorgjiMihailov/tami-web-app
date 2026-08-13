<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalary extends Model
{
    use HasFactory;

    public const BASES = ['gross', 'net'];

    protected $fillable = ['employee_id', 'effective_from', 'amount', 'basis'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'amount' => 'float'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
