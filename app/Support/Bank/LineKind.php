<?php

namespace App\Support\Bank;

enum LineKind: string
{
    case INVOICE_PAYMENT = 'invoice_payment';
    case ACCOUNT = 'account';
    case UNCLEAR = 'unclear';

    public function label(): string
    {
        return match ($this) {
            self::INVOICE_PAYMENT => 'Плаќање на фактура',
            self::ACCOUNT => 'Конто',
            self::UNCLEAR => 'Неразјаснето',
        };
    }
}
