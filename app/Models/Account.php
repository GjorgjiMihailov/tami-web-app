<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'code', 'name', 'parent_code', 'is_analytical', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_analytical' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Account $account) {
            $account->class = substr($account->code, 0, 1);
            $account->group = substr($account->code, 0, 2);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
