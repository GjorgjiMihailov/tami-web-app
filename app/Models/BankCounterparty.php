<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запомнета другата страна на ставка од извод: по нејзината сметка се знае кој
 * е партнерот и на кое конто оди. Се полни при книжење (потврдени податоци).
 */
class BankCounterparty extends Model
{
    protected $fillable = ['company_id', 'account_key', 'direction', 'name_seen', 'partner_id', 'account_id', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
