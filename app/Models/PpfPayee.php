<?php

namespace App\Models;

use Database\Factories\PpfPayeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Физичко лице на кое се исплаќа (договор на дело, авторски, регрес…), за аконтативната пресметка. */
class PpfPayee extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'name', 'surname', 'embg', 'municipality_code', 'bank_account'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PpfPayment::class);
    }

    public function fullName(): string
    {
        return trim($this->name.' '.$this->surname);
    }

    protected static function newFactory(): PpfPayeeFactory
    {
        return PpfPayeeFactory::new();
    }
}
