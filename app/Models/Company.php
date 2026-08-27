<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    public const EFAKTURA_MODE_OWN = 'own';

    public const EFAKTURA_MODE_FIRM = 'firm';

    public const EFAKTURA_STATUS_NONE = 'none';

    public const EFAKTURA_STATUS_REQUESTED = 'requested';

    public const EFAKTURA_STATUS_APPROVED = 'approved';

    public const EFAKTURA_STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'name', 'short_name', 'tax_id', 'mpin_obvrznik_code', 'registration_number', 'nkd_code', 'nkd_name',
        'email', 'phone', 'address', 'street_address', 'street_number', 'postal_code', 'city',
        'website', 'director_name', 'director_phone', 'director_email',
        'logo_path', 'logo_position', 'is_vat_registered', 'invoice_footer_note',
        'efaktura_credential_mode', 'efaktura_eujp_id', 'efaktura_firm_access_status',
        'efaktura_firm_access_decided_by', 'efaktura_firm_access_decided_at',
        'efaktura_token_serial_number', 'efaktura_token_subject_name',
        'efaktura_token_not_before', 'efaktura_token_not_after', 'efaktura_token_registered_at',
        'efaktura_purchase_last_checked_at', 'type',
    ];

    protected function casts(): array
    {
        return [
            'is_vat_registered' => 'boolean',
            'mpin_obvrznik_code' => \App\Support\Payroll\MpinObvrznik::class,
            'type' => \App\Support\CompanyType::class,
            'efaktura_token_not_before' => 'datetime',
            'efaktura_token_not_after' => 'datetime',
            'efaktura_token_registered_at' => 'datetime',
            'efaktura_purchase_last_checked_at' => 'datetime',
        ];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function accountants(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class)->orderBy('position');
    }

    public function incomingEfakturaDocuments(): HasMany
    {
        return $this->hasMany(IncomingEfakturaDocument::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'efaktura_firm_access_decided_by');
    }

    public function hasEfakturaAccess(): bool
    {
        if ($this->efaktura_credential_mode === self::EFAKTURA_MODE_OWN) {
            return filled($this->efaktura_eujp_id) && filled($this->efaktura_token_serial_number);
        }

        return $this->efaktura_firm_access_status === self::EFAKTURA_STATUS_APPROVED;
    }
}
