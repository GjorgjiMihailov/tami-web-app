<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerContact extends Model
{
    protected $fillable = ['partner_id', 'position', 'salutation', 'first_name', 'last_name', 'email', 'phone', 'mobile'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->salutation, $this->first_name, $this->last_name])));
    }
}
