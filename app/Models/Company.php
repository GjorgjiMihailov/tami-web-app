<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'tax_id', 'email', 'phone', 'address', 'logo_path', 'bank_account', 'is_vat_registered'];

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
}
