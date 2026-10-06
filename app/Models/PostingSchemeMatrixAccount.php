<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostingSchemeMatrixAccount extends Model
{
    protected $fillable = ['posting_scheme_id', 'matrix_key', 'item_kind', 'vat_group', 'account_id'];

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(PostingScheme::class, 'posting_scheme_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
