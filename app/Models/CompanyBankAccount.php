<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyBankAccount extends Model
{
    protected $fillable = ['company_id', 'bank_name', 'account_number', 'iban', 'swift', 'position', 'journal_group_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Група на налози на изводите на оваа сметка (10, 11, 12…). */
    public function journalGroup(): BelongsTo
    {
        return $this->belongsTo(JournalGroup::class);
    }
}
