<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Испратница: доказ за движење стока, издадена од потврдена профактура или
 * потврдена фактура (deliverable). Нема сопствени ставки — количините се
 * читаат живо од изворот при секое прикажување/печатење.
 */
class DeliveryNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'deliverable_type', 'deliverable_id', 'fiscal_year',
        'delivery_note_number', 'delivery_note_number_formatted', 'delivery_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function deliverable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
