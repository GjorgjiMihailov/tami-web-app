<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Профактура: понуда со цени пред да има фактура. Нема ДДВ-книжење, залиха
 * ниту е-Фактура — тоа почнува дури кога ќе се претвори во излезна фактура.
 */
class ProformaInvoice extends Model
{
    use HasFactory;

    public const STATUSES = ['draft', 'confirmed', 'converted', 'cancelled'];

    protected $fillable = [
        'company_id', 'partner_id', 'fiscal_year', 'proforma_number', 'proforma_number_formatted',
        'reference', 'proforma_date', 'expected_delivery_date', 'payment_terms_days', 'currency',
        'status', 'notes', 'terms', 'sales_invoice_id', 'created_by',
    ];

    protected $attributes = [
        'currency' => 'MKD',
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'proforma_date' => 'date',
            'expected_delivery_date' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProformaInvoiceLine::class)->orderBy('id');
    }

    public function subtotal(): string
    {
        return $this->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->lineTotal(), 2), '0.00');
    }

    public function vatTotal(): string
    {
        return $this->lines->reduce(fn ($carry, $line) => bcadd($carry, $line->vatAmount(), 2), '0.00');
    }

    public function grandTotal(): string
    {
        return bcadd($this->subtotal(), $this->vatTotal(), 2);
    }

    /** Уредување и потврдување важат само додека не е претворена или откажана. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'confirmed'], true);
    }

    public function isForeignCurrency(): bool
    {
        return $this->currency !== 'MKD';
    }
}
