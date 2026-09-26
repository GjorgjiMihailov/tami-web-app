<?php

namespace App\Models;

use App\Support\VatMath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = ['purchase_invoice_id', 'item_id', 'account_id', 'stock_movement_id', 'description', 'quantity', 'unit_price', 'unit_price_gross', 'vat_rate', 'discount_percent', 'vat_deductible', 'needs_review'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'unit_price_gross' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'vat_deductible' => 'boolean',
            'needs_review' => 'boolean',
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
    private function amounts(?string $discount = null): array
    {
        $discount ??= (string) $this->discount_percent;

        return $this->isGrossEntered()
            ? VatMath::lineFromGross((string) $this->quantity, (string) $this->unit_price_gross, (string) $this->vat_rate, $discount)
            : VatMath::lineFromNet((string) $this->quantity, (string) $this->unit_price, (string) $this->vat_rate, $discount);
    }

    public function hasDiscount(): bool
    {
        return bccomp((string) $this->discount_percent, '0', 2) > 0;
    }

    /** Основицата на ставката пред рабатот. */
    public function originalLineTotal(): string
    {
        return $this->amounts('0')['net'];
    }

    /** Колку рабат е одбиен од основицата на ставката. */
    public function discountAmount(): string
    {
        return bcsub($this->originalLineTotal(), $this->lineTotal(), 2);
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
     * Оди во залихата и во е-Фактура, каде мора да се множи чисто.
     */
    public function effectiveUnitPrice(): string
    {
        return ($this->isGrossEntered() || $this->hasDiscount())
            ? VatMath::unitPriceFromNetTotal($this->lineTotal(), (string) $this->quantity)
            : (string) $this->unit_price;
    }

    /** Нето цената по единица пред рабатот. */
    public function originalUnitPrice(): string
    {
        return $this->isGrossEntered()
            ? VatMath::unitPriceFromNetTotal($this->originalLineTotal(), (string) $this->quantity)
            : (string) $this->unit_price;
    }
}
