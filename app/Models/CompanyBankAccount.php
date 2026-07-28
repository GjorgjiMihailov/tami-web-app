<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyBankAccount extends Model
{
    protected $fillable = ['company_id', 'bank_name', 'account_number', 'position'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
