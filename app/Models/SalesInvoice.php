<?php

namespace App\Models;

use App\Models\Concerns\HasInvoiceTotals;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SalesInvoice extends Model
{
    use HasFactory;
    use HasInvoiceTotals;

    public const PAYMENT_TYPES = [
        'P10' => 'Готово',
        'P11' => 'Картичка',
        'P12' => 'Плаќање преку банка',
        'P13' => 'Рати',
        'P14' => 'Онлајн-банка',
        'P15' => 'Мобилна апликација',
        'P16' => 'Без надомест',
        'P17' => 'Компензација',
        'P18' => 'Ваучер',
        'P19' => 'Друго',
    ];

    protected $fillable = [
        'company_id', 'partner_id', 'warehouse_id', 'journal_entry_id',
        'fiscal_year', 'invoice_number', 'invoice_date', 'due_date',
        'status', 'payment_type_code', 'sent_at', 'notes', 'created_by',
        'efaktura_status', 'efaktura_doc_id', 'efaktura_sent_at', 'efaktura_error',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'efaktura_sent_at' => 'datetime',
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

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
