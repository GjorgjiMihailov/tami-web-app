<?php

namespace App\Support\Posting;

enum PostingDocType: string
{
    case SALES_INVOICE = 'sales_invoice';
    case SALES_PAYMENT = 'sales_payment';
    case PURCHASE_INVOICE = 'purchase_invoice';
    case PURCHASE_PAYMENT = 'purchase_payment';

    public function label(): string
    {
        return match ($this) {
            self::SALES_INVOICE => 'Излезна фактура',
            self::SALES_PAYMENT => 'Уплата од купувач',
            self::PURCHASE_INVOICE => 'Влезна фактура',
            self::PURCHASE_PAYMENT => 'Исплата кон добавувач',
        };
    }
}
