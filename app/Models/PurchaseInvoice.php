<?php

namespace App\Models;

use App\Models\Concerns\HasInvoiceTotals;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseInvoice extends Model
{
    use HasFactory;
    use HasInvoiceTotals;

    protected $fillable = [
        'company_id', 'partner_id', 'warehouse_id', 'journal_entry_id',
        'supplier_invoice_number', 'order_number', 'invoice_date', 'due_date',
        'status', 'notes', 'created_by',
        'is_import', 'customs_declaration_number', 'import_date',
        'import_currency_code', 'import_exchange_rate',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'is_import' => 'boolean',
            'import_date' => 'date',
            'import_exchange_rate' => 'decimal:4',
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
        return $this->hasMany(PurchaseInvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseInvoicePayment::class);
    }

    public function importCosts(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceImportCost::class)->orderBy('sort_order');
    }

    public function tariffLines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceTariffLine::class)->orderBy('sort_order');
    }

    public function incomingEfakturaDocument(): HasOne
    {
        return $this->hasOne(IncomingEfakturaDocument::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
