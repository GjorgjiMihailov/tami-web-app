<?php

namespace App\Support\Posting;

enum ItemKind: string
{
    case GOODS = 'goods';
    case SERVICE = 'service';

    public function label(): string
    {
        return $this === self::GOODS ? 'Стока' : 'Услуга';
    }
}
