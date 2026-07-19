<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'entry_date', 'description', 'created_by'];

    protected function casts(): array
    {
        return ['entry_date' => 'date'];
    }

    // NOTE: lockForUpdate() here only holds a real lock when the caller wraps
    // JournalEntry::create(...) in DB::transaction(...) — see JournalEntryForm::save().
    protected static function booted(): void
    {
        static::creating(function (JournalEntry $entry) {
            $entry->fiscal_year = Carbon::parse($entry->entry_date)->year;

            $max = static::where('company_id', $entry->company_id)
                ->where('fiscal_year', $entry->fiscal_year)
                ->lockForUpdate()
                ->max('entry_number');

            $entry->entry_number = ($max ?? 0) + 1;
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isBalanced(): bool
    {
        $totals = $this->lines()->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')->first();

        return round((float) $totals->total_debit, 2) === round((float) $totals->total_credit, 2);
    }
}
