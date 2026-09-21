<?php

namespace App\Models;

use App\Support\VatMath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLine extends Model
{
    use HasFactory;

    public const TREATMENTS = ['standard', 'export', 'exempt_with_credit', 'exempt_without_credit'];

    protected $fillable = ['sales_invoice_id', 'item_id', 'stock_movement_id', 'description', 'quantity', 'unit_price', 'unit_price_gross', 'vat_rate', 'vat_treatment'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'unit_price_gross' => 'decimal:2',
            'vat_rate' => 'decimal:2',
        ];
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /**
     * Дали ставката е внесена со цена СО ДДВ. Тогаш вкупното со ДДВ е она што
     * мора да излезе точно, а основицата се вади наназад од него.
     */
    public function isGrossEntered(): bool
    {
        return $this->unit_price_gross !== null;
    }

    /**
     * @return array{net: string, vat: string, gross: string}
     */
    private function amounts(): array
    {
        return $this->isGrossEntered()
            ? VatMath::lineFromGross((string) $this->quantity, (string) $this->unit_price_gross, (string) $this->vat_rate)
            : VatMath::lineFromNet((string) $this->quantity, (string) $this->unit_price, (string) $this->vat_rate);
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

    /**
     * Нето цена по единица што помножена со количината ја дава основицата.
     * Оди во е-Фактура и на печатената фактура, каде мора да се множи чисто.
     */
    public function effectiveUnitPrice(): string
    {
        return $this->isGrossEntered()
            ? VatMath::unitPriceFromNetTotal($this->lineTotal(), (string) $this->quantity)
            : (string) $this->unit_price;
    }
}
