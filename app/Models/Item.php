<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'unit_of_measure', 'category',
        'vat_rate', 'preferred_partner_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function preferredPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'preferred_partner_id');
    }
}
