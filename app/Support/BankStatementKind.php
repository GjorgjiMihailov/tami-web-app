<?php

namespace App\Support;

/**
 * Денарски или девизен извод.
 *
 * Разликата не е само етикета: двете сметки одат во сопствени низи со сопствено
 * броење, па изводот 47 на девизната нема врска со изводот 47 на денарската.
 */
enum BankStatementKind: string
{
    case DENAR = 'denar';
    case FOREIGN = 'foreign';

    public function label(): string
    {
        return match ($this) {
            self::DENAR => 'Денарски',
            self::FOREIGN => 'Девизен',
        };
    }

    public function isDenar(): bool
    {
        return $this === self::DENAR;
    }

    public function isForeign(): bool
    {
        return $this === self::FOREIGN;
    }
}
