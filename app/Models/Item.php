<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    use HasFactory;

    public const TYPES = ['product', 'service'];

    protected $fillable = [
        'company_id', 'code', 'name', 'unit_of_measure', 'category',
        'vat_rate', 'preferred_partner_id', 'is_active',
        'selling_price', 'type', 'is_made_in_mk', 'barcode',
    ];

    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_made_in_mk' => 'boolean',
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

    public function isService(): bool
    {
        return $this->type === 'service';
    }
}
