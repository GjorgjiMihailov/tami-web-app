<?php

namespace App\Models;

use App\Support\Posting\PostingDocType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Личен предложен комплет шеми на сметководител: еден запис по вид документ, конта по шифра. */
class UserPostingScheme extends Model
{
    protected $fillable = ['user_id', 'doc_type', 'name', 'definition'];

    protected function casts(): array
    {
        return ['doc_type' => PostingDocType::class, 'definition' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
