<?php

namespace App\Models;

use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Една ставка од извод: уплата или исплата, и во што се претвора при книжењето. */
class BankStatementLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_statement_id', 'position', 'line_date', 'direction', 'amount', 'partner_id',
        'description', 'reference_number', 'purpose_code', 'kind', 'account_id',
        'sales_invoice_id', 'purchase_invoice_id', 'sales_invoice_payment_id',
        'purchase_invoice_payment_id', 'created_payment',
    ];

    // DB-default не го полни свеж модел во меморија.
    protected $attributes = ['created_payment' => false, 'position' => 0];

    protected function casts(): array
    {
        return [
            'line_date' => 'date',
            'direction' => LineDirection::class,
            'kind' => LineKind::class,
            'amount' => 'decimal:2',
            'created_payment' => 'boolean',
        ];
    }

    /** Со знак од наша гледна точка: + за уплата, − за исплата. */
    public function signedAmount(): string
    {
        $amount = (string) $this->amount;

        return $this->direction === LineDirection::OUT ? bcmul($amount, '-1', 2) : bcadd($amount, '0', 2);
    }

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function salesInvoicePayment(): BelongsTo
    {
        return $this->belongsTo(SalesInvoicePayment::class);
    }

    public function purchaseInvoicePayment(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoicePayment::class);
    }
}
