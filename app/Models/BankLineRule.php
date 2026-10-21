<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запомнето конто за еден вид ставка од извод: сметка на другата страна, насока
 * и образец на текстот (BankLinePattern). Се полни при книжење; сметководителот
 * го прегледува и менува на екранот „Запомнати конта“.
 */
class BankLineRule extends Model
{
    protected $fillable = [
        'company_id', 'account_key', 'direction', 'pattern', 'example_text', 'name_seen',
        'account_id', 'partner_id', 'times_used', 'last_used_at',
    ];

    protected $attributes = ['times_used' => 0];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
