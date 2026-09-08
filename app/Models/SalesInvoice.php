<?php

namespace App\Models;

use App\Models\Concerns\HasInvoiceTotals;
use App\Support\InvoiceNumber;
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

    // Per the approved design doc (2026-08-05-efaktura-status-and-pdf-design.md §Д) — not yet
    // independently re-verified against a live УЈП response. If Task 8's live test surfaces
    // different codes for "Прифатена"/"Автоматски прифатена", fix them here only.
    public const EFAKTURA_ACCEPTED_STATUS_CODES = ['03', '04'];

    protected $fillable = [
        'company_id', 'partner_id', 'warehouse_id', 'journal_entry_id',
        'fiscal_year', 'invoice_number', 'invoice_number_formatted', 'invoice_date', 'due_date',
        'status', 'payment_type_code', 'sent_at', 'notes', 'created_by',
        'efaktura_status', 'efaktura_doc_id', 'efaktura_sent_at', 'efaktura_error',
        'efaktura_ujp_status_code', 'efaktura_ujp_status_name', 'efaktura_pdf_path',
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

    /**
     * Бројот што стои на фактурата.
     *
     * Замрзнатиот текст е вистината — се запишува при потврдување и подоцнежна
     * промена на форматот на фирмата не го допира. Пресметката во лет служи
     * само за редови што останале без текст (нацрт што сè уште нема број, или
     * ред создаден заобиколувајќи го сервисот во тест).
     */
    public function formattedNumber(): ?string
    {
        if (filled($this->invoice_number_formatted)) {
            return $this->invoice_number_formatted;
        }

        if ($this->invoice_number === null) {
            return null;
        }

        return InvoiceNumber::format($this->company, (int) $this->fiscal_year, (int) $this->invoice_number);
    }

    public function isEfakturaAccepted(): bool
    {
        return in_array($this->efaktura_ujp_status_code, self::EFAKTURA_ACCEPTED_STATUS_CODES, true);
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
