<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostingSchemeRow extends Model
{
    protected $fillable = [
        'posting_scheme_id', 'position', 'account_mode', 'account_id', 'matrix_key',
        'side', 'formula', 'with_partner', 'description', 'condition',
    ];

    // DB-default не го полни свеж модел во меморија.
    protected $attributes = ['with_partner' => false];

    protected function casts(): array
    {
        return ['with_partner' => 'boolean'];
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(PostingScheme::class, 'posting_scheme_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
