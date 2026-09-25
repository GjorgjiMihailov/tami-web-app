<?php

namespace App\Models;

use App\Support\VatMath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProformaInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = ['proforma_invoice_id', 'item_id', 'description', 'quantity', 'unit_price', 'vat_rate', 'discount_percent'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'discount_percent' => 'decimal:2',
        ];
    }

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return array{net: string, vat: string, gross: string} */
    private function amounts(): array
    {
        return VatMath::lineFromNet((string) $this->quantity, (string) $this->unit_price, (string) $this->vat_rate, (string) $this->discount_percent);
    }

    public function lineTotal(): string
    {
        return $this->amounts()['net'];
    }

    public function vatAmount(): string
    {
        return $this->amounts()['vat'];
    }

    public function grossTotal(): string
    {
        return $this->amounts()['gross'];
    }
}
