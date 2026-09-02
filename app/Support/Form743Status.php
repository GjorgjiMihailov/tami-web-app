<?php

namespace App\Support;

/**
 * Каде стои еден 743 образец во работата на канцеларијата.
 *
 * Образецот доаѓа од банка со сите податоци веќе пополнети — клиентот само го
 * качува. Работата што останува е човечка: некој од канцеларијата го чита и ја
 * внесува е-ПДД пријавата рачно во порталот на УЈП, зашто е-ПДД нема API.
 *
 * Затоа состојбите се две, не повеќе: чека на некого, или е внесена.
 */
enum Form743Status: string
{
    case PENDING = 'pending';
    case FILED = 'filed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Необработен',
            self::FILED => 'Внесен',
        };
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isFiled(): bool
    {
        return $this === self::FILED;
    }
}
