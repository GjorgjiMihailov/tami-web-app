<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Partner extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'name', 'type', 'tax_id', 'registration_number',
        'director_name', 'is_vat_registered', 'vat_number',
        'email', 'phone', 'address',
    ];

    protected function casts(): array
    {
        return ['is_vat_registered' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
