<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'short_name', 'tax_id', 'registration_number', 'nkd_code', 'nkd_name',
        'email', 'phone', 'address', 'website', 'director_name', 'director_phone', 'director_email',
        'logo_path', 'logo_position', 'bank_account', 'is_vat_registered', 'invoice_footer_note',
    ];

    protected function casts(): array
    {
        return ['is_vat_registered' => 'boolean'];
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
}
