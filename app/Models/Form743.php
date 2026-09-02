<?php

namespace App\Models;

use App\Support\Form743Status;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Потврда од банка за приход исплатен од странство.
 *
 * Банката ја пополнува: плаќач, износ, девиза, датум и основ се веќе на неа кога
 * стигнува до клиентот. Македонските исплати немаат таков образец.
 *
 * Записот не постои за да ги чува тие податоци — постои за да создаде **работа**.
 * е-ПДД нема API, па пријавата секогаш ја внесува човек во порталот на УЈП; ова
 * само се грижи да не се заборави ниту еден образец и да се знае кој што внел.
 */
class Form743 extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'status', 'payer', 'amount', 'currency',
        'payment_date', 'basis', 'uploaded_by', 'filed_by', 'filed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => Form743Status::class,
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'filed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    /** Самиот образец, преку постоечката полиморфна табела за документи. */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
