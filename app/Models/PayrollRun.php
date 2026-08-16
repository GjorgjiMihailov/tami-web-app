<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use HasFactory;

    public const DRAFT = 'draft';

    public const CONFIRMED = 'confirmed';

    protected $fillable = [
        'company_id', 'year', 'month', 'status', 'month_hours',
        'payroll_parameter_id', 'journal_entry_id', 'confirmed_by', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'month_hours' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(PayrollParameter::class, 'payroll_parameter_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(PayrollRunEmployee::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** The date the run is booked on and the date its salaries are read at. */
    public function endOfMonth(): string
    {
        return Carbon::create($this->year, $this->month, 1)->endOfMonth()->toDateString();
    }

    public function monthName(): string
    {
        return [
            1 => 'Јануари', 2 => 'Февруари', 3 => 'Март', 4 => 'Април',
            5 => 'Мај', 6 => 'Јуни', 7 => 'Јули', 8 => 'Август',
            9 => 'Септември', 10 => 'Октомври', 11 => 'Ноември', 12 => 'Декември',
        ][$this->month];
    }
}
