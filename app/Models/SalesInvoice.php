<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'partner_id', 'warehouse_id', 'journal_entry_id',
        'fiscal_year', 'invoice_number', 'invoice_date', 'due_date',
        'status', 'sent_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
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

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesInvoicePayment::class);
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

    public function paidTotal(): string
    {
        return $this->payments->reduce(fn ($carry, $payment) => bcadd($carry, (string) $payment->amount, 2), '0.00');
    }

    public function balanceDue(): string
    {
        return bcsub($this->grandTotal(), $this->paidTotal(), 2);
    }

    public function paymentStatus(): string
    {
        if ($this->status !== 'confirmed') {
            return 'n/a';
        }

        $paid = $this->paidTotal();

        if (bccomp($paid, '0', 2) <= 0) {
            return 'unpaid';
        }

        if (bccomp($paid, $this->grandTotal(), 2) >= 0) {
            return 'paid';
        }

        return 'partially_paid';
    }

    public function isOverdue(): bool
    {
        return in_array($this->paymentStatus(), ['unpaid', 'partially_paid'], true)
            && $this->due_date->isPast();
    }
}
