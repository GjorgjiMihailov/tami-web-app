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
        'description', 'cost_price', 'purchase_vat_rate', 'is_sellable', 'is_purchasable',
    ];

    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'purchase_vat_rate' => 'decimal:2',
            'is_sellable' => 'boolean',
            'is_purchasable' => 'boolean',
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

    /** ДДВ при набавка: посебна стапка ако е зададена, инаку истата како при продажба. */
    public function purchaseVatRate(): string
    {
        return (string) ($this->purchase_vat_rate ?? $this->vat_rate);
    }

    public function isService(): bool
    {
        return $this->type === 'service';
    }
}
