<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceImportCost extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_invoice_id', 'payee_name', 'reference_number',
        'foreign_amount', 'base_amount', 'vat_amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'foreign_amount' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
        ];
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }
}
