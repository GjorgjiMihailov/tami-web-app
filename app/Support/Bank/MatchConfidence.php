<?php

namespace App\Support\Bank;

/** Колку е сигурно препознаена една ставка од извод. */
enum MatchConfidence: string
{
    case SURE = 'sure';
    case CHECK = 'check';
    case UNCLEAR = 'unclear';

    public function label(): string
    {
        return match ($this) {
            self::SURE => 'Сигурно',
            self::CHECK => 'Проверете',
            self::UNCLEAR => 'Нејасно',
        };
    }

    /** Само „сигурно“ не бара преглед од човек. */
    public function needsReview(): bool
    {
        return $this !== self::SURE;
    }
}
