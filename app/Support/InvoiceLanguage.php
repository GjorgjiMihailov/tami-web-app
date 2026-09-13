<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * На кој јазик „звучи" една фактура.
 *
 * Еден темплејт печати и македонска и англиска фактура. Распоредот е ист —
 * уплатницата, ДДВ по ставка, линиите за потпис — менуваат се само зборовите,
 * разделниците на броевите и обликот на датумот. Сите три живеат овде, за да
 * не се раздвои темплејтот на две копии што потоа се поправаат двапати.
 *
 * Македонската страна намерно ги повикува постоечките `Format::` методи
 * наместо да ги повторува: така печатената денарска фактура е докажливо
 * непроменета, а не „изгледа исто".
 */
enum InvoiceLanguage: string
{
    case MK = 'mk';
    case EN = 'en';

    public function label(): string
    {
        return match ($this) {
            self::MK => 'Македонски',
            self::EN => 'English',
        };
    }

    /**
     * Еден збор од фактурата. Непознат клуч се враќа каков што е — празна
     * ќелија на испечатена хартија е полоша од чуден збор.
     */
    public function t(string $key): string
    {
        return self::DICTIONARY[$key][$this->value] ?? $key;
    }

    public function money(string|float|int $amount, string $currency = 'MKD'): string
    {
        if ($this === self::MK) {
            return Format::money($amount, $currency === 'MKD' ? 'ден' : $currency);
        }

        return number_format((float) $amount, 2, '.', ',').' '.$currency;
    }

    public function date(mixed $value): string
    {
        if ($this === self::MK) {
            return Format::date($value);
        }

        // „13 Sep 2026“, не „13.09.2026“ — американски клиент второто го чита
        // како 9 септември.
        return Carbon::parse($value)->format('j M Y');
    }

    public function vatTreatment(string $treatment): string
    {
        if ($this === self::MK) {
            return Format::vatTreatment($treatment);
        }

        return match ($treatment) {
            'standard' => 'Standard',
            'export' => 'Export',
            'exempt_with_credit' => 'exempt with input tax credit',
            'exempt_without_credit' => 'exempt without input tax credit',
            default => str_replace('_', ' ', $treatment),
        };
    }

    private const DICTIONARY = [
        'invoice' => ['mk' => 'ФАКТУРА', 'en' => 'INVOICE'],
        'invoice_date' => ['mk' => 'Датум на фактура', 'en' => 'Invoice date'],
        'due_date' => ['mk' => 'Датум на доспевање', 'en' => 'Due date'],
        'seller' => ['mk' => 'Издавач', 'en' => 'Seller'],
        'buyer' => ['mk' => 'Купувач', 'en' => 'Buyer'],
        'tax_id' => ['mk' => 'ЕДБ', 'en' => 'Tax no.'],
        'registration_number' => ['mk' => 'ЕМБС', 'en' => 'Reg. no.'],
        'line_no' => ['mk' => 'Р.б.', 'en' => 'No.'],
        'description' => ['mk' => 'Опис', 'en' => 'Description'],
        'quantity' => ['mk' => 'Кол.', 'en' => 'Qty'],
        'unit_price' => ['mk' => 'Ед. цена', 'en' => 'Unit price'],
        'vat_percent' => ['mk' => 'ДДВ %', 'en' => 'VAT %'],
        'vat_amount' => ['mk' => 'Износ на ДДВ', 'en' => 'VAT amount'],
        'total_with_vat' => ['mk' => 'Вкупно со ДДВ', 'en' => 'Total incl. VAT'],
        'total' => ['mk' => 'Вкупно', 'en' => 'Total'],
        'payment_details' => ['mk' => 'Начин на плаќање', 'en' => 'Payment details'],
        'beneficiary' => ['mk' => 'Назив на примач', 'en' => 'Beneficiary'],
        'beneficiary_bank' => ['mk' => 'Банка на примач', 'en' => 'Bank'],
        'account' => ['mk' => 'Сметка', 'en' => 'Account'],
        'iban' => ['mk' => 'IBAN', 'en' => 'IBAN'],
        'swift' => ['mk' => 'SWIFT', 'en' => 'SWIFT'],
        'amount' => ['mk' => 'Износ', 'en' => 'Amount'],
        'payment_reference' => ['mk' => 'Цел на дознака', 'en' => 'Payment reference'],
        'no_bank_account' => ['mk' => 'Нема внесена банкарска сметка.', 'en' => 'No bank account on file.'],
        'subtotal' => ['mk' => 'Основа', 'en' => 'Subtotal'],
        'vat' => ['mk' => 'ДДВ', 'en' => 'VAT'],
        'balance_due' => ['mk' => 'За доплата', 'en' => 'Balance due'],
        'signature_issuer' => ['mk' => 'ОВЛАСТЕНО ЛИЦЕ', 'en' => 'AUTHORISED SIGNATURE'],
        'signature_receiver' => ['mk' => 'ПРИМИЛ', 'en' => 'RECEIVED BY'],
        'not_vat_registered' => ['mk' => 'Фирмава не е ДДВ обврзник.', 'en' => 'Not registered for VAT.'],
        'seller_country' => ['mk' => 'Северна Македонија', 'en' => 'North Macedonia'],
    ];
}
