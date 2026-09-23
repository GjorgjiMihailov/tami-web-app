<?php

namespace App\Support;

/**
 * Што е клиентот, и оттаму што гледа.
 *
 * Правно лице е фирма: главна книга, ДДВ, залиха, плати. Физичко лице е човек
 * со ЕМБГ и без ЕДБ — приход од изнајмување, авторски хонорар, договор на дело
 * — што повремено издава и излезна фактура. Тие два профила делат многу малку,
 * па типот се чита на секое место каде разликата има значење: менито,
 * пристапот, полињата на профилот и почетниот екран.
 *
 * Се бира при создавање и никогаш не се менува — одлука на корисникот. Затоа
 * нема поле за уредување, само избор во формата за нов профил.
 */
enum CompanyType: string
{
    case LEGAL = 'legal';
    case INDIVIDUAL = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::LEGAL => 'Правно лице',
            self::INDIVIDUAL => 'Физичко лице',
        };
    }

    public function isLegal(): bool
    {
        return $this === self::LEGAL;
    }

    public function isIndividual(): bool
    {
        return $this === self::INDIVIDUAL;
    }

    /**
     * Улогата што ја добива секој нов клиентски профил на фирма од овој тип.
     * Заклучена врска — нема начин низ UI да се додели спротивното.
     */
    public function clientRole(): string
    {
        return match ($this) {
            self::LEGAL => 'internal_client',
            self::INDIVIDUAL => 'freelancer_client',
        };
    }
}
