<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingEfakturaDocument extends Model
{
    use HasFactory;

    public const DECISION_ACCEPTED = 'accepted';

    public const DECISION_REJECTED = 'rejected';

    // "O-7" here uses the Cyrillic О (U+041E), matching the УЈП shifrarnik below — kept as an
    // explicit unicode escape so it can never silently drift to the Latin "O" via editor
    // encoding, the same precaution as the "МК" VAT-number prefix in EfakturaDocumentBuilder.
    public const REJECT_REASON_OTHER = "\u{041E}-7";

    // УЈП shifrarnik "ПРИЧИНИ ЗА ОДБИВАЊЕ НА Е-ФАКТУРА", captured verbatim from the official
    // шифрарници.pdf, supplied directly by the user 2026-08-06 — not re-typed/guessed from
    // memory. NOTE the mixed script: O-1 through O-5 use the Latin letter O, while О-6 and О-7
    // use the Cyrillic letter О (U+041E). This is exactly the kind of MK/МК homoglyph trap this
    // project has been burned by before (see EfakturaDocumentBuilder::buildParty()) — preserve
    // verbatim, do NOT "fix" the apparent inconsistency.
    public const REJECT_REASONS = [
        'O-1' => 'Погрешно пресметан ДДВ (несоодветна даночна основа, ДДВ стапка, неправилен даночен индикатор и сл.)',
        'O-2' => 'Грешка во нарачка (количина, цена, опис на промет и друго)',
        'O-3' => 'Погрешни податоци за купувач (едб, назив, адреса и друго)',
        'O-4' => 'Прометот не е извршен',
        'O-5' => 'Дупликат фактура',
        "\u{041E}-6" => 'Погрешен датум (издавање/промет)',
        self::REJECT_REASON_OTHER => '*Друго (внес на слободен текст)',
    ];

    protected $fillable = [
        'company_id', 'euid', 'status_code', 'status_name',
        'doc_number', 'doc_date', 'seller_name', 'seller_tax_id', 'total_amount',
        'payload_json', 'discovered_at', 'decision', 'decided_at', 'decided_by',
        'reject_reason_code', 'reject_comment', 'purchase_invoice_id', 'efaktura_pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
            'total_amount' => 'decimal:2',
            'payload_json' => 'array',
            'discovered_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
