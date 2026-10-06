<?php

namespace App\Models;

use App\Support\Posting\PostingDocType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Шема за книжење на еден вид документ, за една фирма. */
class PostingScheme extends Model
{
    protected $fillable = ['company_id', 'doc_type', 'name'];

    protected function casts(): array
    {
        return ['doc_type' => PostingDocType::class];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PostingSchemeRow::class)->orderBy('position')->orderBy('id');
    }

    public function matrixAccounts(): HasMany
    {
        return $this->hasMany(PostingSchemeMatrixAccount::class);
    }
}
