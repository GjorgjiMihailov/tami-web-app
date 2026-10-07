<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    use HasFactory;

    protected $fillable = ['item_id', 'warehouse_id', 'quantity_on_hand', 'average_cost', 'import_value'];

    // DB-default не го полни свеж модел во меморија.
    protected $attributes = ['import_value' => '0'];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:3',
            'average_cost' => 'decimal:4',
            'import_value' => 'decimal:6',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
