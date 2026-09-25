<?php

namespace App\Models;

use App\Support\InvoiceLanguage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Partner extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'name', 'type', 'tax_id', 'registration_number',
        'director_name', 'is_vat_registered', 'vat_number',
        'email', 'phone', 'address', 'street_address', 'street_number', 'postal_code', 'city',
        'invoice_language', 'country',
        'contact_salutation', 'contact_first_name', 'contact_last_name', 'mobile', 'payment_terms_days',
        'shipping_street_address', 'shipping_street_number', 'shipping_postal_code', 'shipping_city', 'shipping_country',
    ];

    /** Понудени рокови на плаќање (денови); 0 = по приемот. */
    public const PAYMENT_TERMS = [0, 8, 15, 30, 45, 60, 90];

    /** Начини на обраќање за примарниот контакт и контакт лицата. */
    public const SALUTATIONS = ['Г-дин', 'Г-ѓа', 'Г-ца', 'Д-р'];

    protected $attributes = [
        'invoice_language' => 'mk',
    ];

    protected function casts(): array
    {
        return [
            'is_vat_registered' => 'boolean',
            'invoice_language' => InvoiceLanguage::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PartnerContact::class)->orderBy('position');
    }

    /**
     * Адресата како што се печати на фактура: слободниот текст ако е внесен,
     * инаку составена од структурираните полиња (улица, број, пошта, град, држава).
     */
    public function printedAddress(): ?string
    {
        if (filled($this->address)) {
            return $this->address;
        }

        $street = trim(implode(' ', array_filter([$this->street_address, $this->street_number])));
        $town = trim(implode(' ', array_filter([$this->postal_code, $this->city])));
        $composed = implode(', ', array_filter([$street, $town, $this->country]));

        return $composed !== '' ? $composed : null;
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(PartnerBankAccount::class)->orderBy('position');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
