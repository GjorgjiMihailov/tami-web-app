<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    use HasFactory;

    public const LEVEL_CLASS = 'class';

    public const LEVEL_GROUP = 'group';

    public const LEVEL_SUBGROUP = 'subgroup';

    public const LEVEL_ACCOUNT = 'account';

    /** Конто на кое се книжат сите банкарски движења (трансакциска сметка во денари). */
    public const BANK_CODE = '1000';

    protected $fillable = [
        'company_id', 'code', 'name', 'parent_code', 'level',
        'is_analytical', 'must_debit', 'must_credit', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_analytical' => 'boolean',
            'must_debit' => 'boolean',
            'must_credit' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Account $account) {
            $account->class = substr($account->code, 0, 1);
            $account->group = substr($account->code, 0, 2);
            $account->level ??= match (strlen($account->code)) {
                1 => self::LEVEL_CLASS,
                2 => self::LEVEL_GROUP,
                3 => self::LEVEL_SUBGROUP,
                default => self::LEVEL_ACCOUNT,
            };
        });
    }

    /** Class and group rows are headings; every other level can carry an entry. */
    public function scopePostable(Builder $query): void
    {
        $query->whereNotIn('level', [self::LEVEL_CLASS, self::LEVEL_GROUP]);
    }

    /** Само аналитичките конта (листовите) примаат книжење од изводи. */
    public function scopeAnalytical(Builder $query): void
    {
        $query->where('is_analytical', true);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
