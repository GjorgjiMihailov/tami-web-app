<?php

namespace App\Models;

use Database\Factories\PpfCalculationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Една аконтативна пресметка = еден ППФ XML фајл („Пресметка бр. N“ во годината).
 * Во ист ден може да има повеќе пресметки: исплатите се доделуваат на пресметка,
 * не се групираат по датум.
 */
class PpfCalculation extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'year', 'number', 'period_date', 'exported_at', 'exported_by', 'created_by'];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'exported_at' => 'datetime',
            'year' => 'integer',
            'number' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PpfPayment::class)->orderBy('id');
    }

    /** Следниот слободен број во годината за фирмата. */
    public static function nextNumber(int $companyId, int $year): int
    {
        return ((int) static::where('company_id', $companyId)->where('year', $year)->max('number')) + 1;
    }

    /** Променета пресметка повторно треба да се симне. */
    public function clearExport(): void
    {
        if ($this->exported_at !== null || $this->exported_by !== null) {
            $this->forceFill(['exported_at' => null, 'exported_by' => null])->save();
        }
    }

    protected static function newFactory(): PpfCalculationFactory
    {
        return PpfCalculationFactory::new();
    }
}
