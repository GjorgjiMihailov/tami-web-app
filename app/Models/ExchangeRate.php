<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = ['rate_date', 'currency_code', 'rate'];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date:Y-m-d',
            'rate' => 'decimal:6',
        ];
    }
}
