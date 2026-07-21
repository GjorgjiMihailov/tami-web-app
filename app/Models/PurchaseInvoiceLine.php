<?php

namespace App\Models;

use App\Support\Bcmath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = ['purchase_invoice_id', 'item_id', 'account_id', 'stock_movement_id', 'description', 'quantity', 'unit_price', 'vat_rate', 'vat_deductible'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_deductible' => 'boolean',
        ];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function lineTotal(): string
    {
        return Bcmath::roundHalfUp(bcmul((string) $this->quantity, (string) $this->unit_price, 10), 2);
    }

    public function vatAmount(): string
    {
        $rate = bcdiv((string) $this->vat_rate, '100', 10);

        return Bcmath::roundHalfUp(bcmul($this->lineTotal(), $rate, 10), 2);
    }
}
