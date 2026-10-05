<?php

namespace App\Models;

use App\Support\BankStatementKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Еден извод од банка, денарски или девизен.
 *
 * Самиот извод (фајлот) се чува како документ. Почетната и крајната состојба се
 * внесуваат САМО за контрола при книжење на денарски извод и никогаш не се
 * книжат; ставките (`lines`) се она што се книжи.
 */
class BankStatement extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_BOOKED = 'booked';

    protected $fillable = [
        'company_id', 'bank', 'account', 'kind', 'number', 'statement_date', 'uploaded_by',
        'opening_balance', 'closing_balance', 'status', 'journal_entry_id',
    ];

    // DB-default не го полни свеж модел во меморија.
    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected function casts(): array
    {
        return [
            'kind' => BankStatementKind::class,
            'number' => 'integer',
            'statement_date' => 'date',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('position')->orderBy('id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isBooked(): bool
    {
        return $this->status === self::STATUS_BOOKED;
    }

    /** Уплати минус исплати, со знак од наша гледна точка. */
    public function movement(): string
    {
        return $this->lines->reduce(fn (string $carry, BankStatementLine $line) => bcadd($carry, $line->signedAmount(), 2), '0.00');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Самиот извод, преку постоечката полиморфна табела за документи. */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
