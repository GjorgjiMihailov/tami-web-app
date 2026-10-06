<?php

namespace App\Support\Bank;

/** Од наша гледна точка: уплата = пари влегуваат на сметката. */
enum LineDirection: string
{
    case IN = 'in';
    case OUT = 'out';

    public function label(): string
    {
        return match ($this) {
            self::IN => 'Уплата',
            self::OUT => 'Исплата',
        };
    }
}
