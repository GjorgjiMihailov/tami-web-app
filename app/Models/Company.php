<?php

namespace App\Models;

use App\Support\CompanyModule;
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
        'name', 'short_name', 'tax_id', 'embg', 'mpin_obvrznik_code', 'registration_number', 'nkd_code', 'nkd_name',
        'email', 'phone', 'address', 'street_address', 'street_number', 'postal_code', 'city',
        'website', 'director_name', 'director_phone', 'director_email',
        'logo_path', 'logo_position', 'is_vat_registered', 'invoice_footer_note',
        'efaktura_credential_mode', 'efaktura_eujp_id', 'efaktura_firm_access_status',
        'efaktura_firm_access_decided_by', 'efaktura_firm_access_decided_at',
        'efaktura_token_serial_number', 'efaktura_token_subject_name',
        'efaktura_token_not_before', 'efaktura_token_not_after', 'efaktura_token_registered_at',
        'efaktura_purchase_last_checked_at', 'type',
        'uses_material', 'uses_stock', 'uses_payroll', 'uses_finance',
    ];

    /**
     * Стандардните вредности на колоните за модули се повторуваат тука намерно.
     * Стандардна вредност во базата важи за самиот ред, но НЕ се враќа во
     * моделот што штотуку е создаден — `Company::create()` без овие клучеви
     * остава `null` во меморија, а `null` за `usesModule()` значи исклучено.
     * Со ова новосоздадената фирма чита исто и во меморија и по повторно
     * читање од базата.
     */
    protected $attributes = [
        'uses_material' => true,
        'uses_stock' => true,
        'uses_payroll' => true,
        'uses_finance' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_vat_registered' => 'boolean',
            'uses_material' => 'boolean',
            'uses_stock' => 'boolean',
            'uses_payroll' => 'boolean',
            'uses_finance' => 'boolean',
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

    public function form743s(): HasMany
    {
        return $this->hasMany(Form743::class);
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

    /**
     * Дали оваа фирма го користи модулот.
     *
     * Единственото место каде се чита состојбата на модул. Менито, плочките и
     * `EnsureCompanyModule` сите поминуваат оттука, за правилото за Залиха и
     * исклучокот за физичко лице да живеат само на едно место.
     */
    public function usesModule(CompanyModule $module): bool
    {
        // Модулите не важат за физичко лице. Типот веќе одлучува што гледа тој
        // профил, па колона со заостаната вредност не смее да му затвори екран
        // што типот му го дава. Истата грешка беше вистинска кај
        // `is_vat_registered`, каде стандардна вредност во базата го запиша
        // физичкото лице како ДДВ обврзник.
        if ($this->type->isIndividual()) {
            return true;
        }

        // Залиха е подмодул: без Материјално не постои, без разлика што пишува
        // во колоната. Формата ја отштиклира и зачувувањето ја запишува како
        // исклучена — ова е третата брана, за ред сменет со рака.
        if ($module === CompanyModule::STOCK && ! $this->uses_material) {
            return false;
        }

        return (bool) $this->{$module->column()};
    }
}
