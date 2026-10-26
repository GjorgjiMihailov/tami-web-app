<?php

namespace App\Models;

use Database\Factories\PpfPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Една исплата на физичко лице — еден ред во калкулацијата на ППФ XML. */
class PpfPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'ppf_calculation_id', 'ppf_payee_id', 'type_income_code', 'subtype_income_code', 'gross_income', 'payment_date',
        'payment_type_code', 'advance', 'vat_amount', 'gross_free', 'decision_number', 'decision_date', 'comment',
        'created_by',
    ];

    protected $attributes = ['payment_type_code' => '00001'];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'decision_date' => 'date',
            'gross_income' => 'integer',
            'advance' => 'integer',
            'vat_amount' => 'integer',
            'gross_free' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function calculation(): BelongsTo
    {
        return $this->belongsTo(PpfCalculation::class, 'ppf_calculation_id');
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(PpfPayee::class, 'ppf_payee_id');
    }

    protected static function newFactory(): PpfPaymentFactory
    {
        return PpfPaymentFactory::new();
    }
}
