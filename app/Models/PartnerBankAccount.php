<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerBankAccount extends Model
{
    protected $fillable = ['partner_id', 'bank_name', 'account_number', 'position'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
