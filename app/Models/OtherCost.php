<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Трошок што не доаѓа како влезна фактура — фискална сметка и слично.
 *
 * Сметките за струја, телефон и вода НЕ се тука: тие доаѓаат како фактура и
 * одат кај влезните фактури. Овде е она за што постои само парче хартија.
 *
 * Записот засега само го чува трошокот и документот. ДДВ и книжењето во
 * главната книга доаѓаат откако структурата ќе се одреди — види ја
 * спецификацијата; празна колона што никој не ја чита е полоша од миграција
 * напишана кога ќе се знае што треба во неа.
 */
class OtherCost extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'cost_date', 'description', 'amount', 'created_by'];

    protected function casts(): array
    {
        return [
            'cost_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Сметката или потврдата, преку постоечката полиморфна табела. */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
