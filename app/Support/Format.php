<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class Format
{
    public static function date(mixed $value): string
    {
        return Carbon::parse($value)->format('d.m.Y');
    }

    public static function money(string|float|int $amount, string $currency = 'ден', int $decimals = 2): string
    {
        $number = number_format((float) $amount, $decimals, ',', '.');

        return $currency === '' ? $number : "{$number} {$currency}";
    }

    public static function invoiceStatus(string $status): string
    {
        return match ($status) {
            'draft' => 'Нацрт',
            'confirmed' => 'Потврдена',
            'cancelled' => 'Откажана',
            default => ucfirst($status),
        };
    }

    public static function paymentStatus(string $status): string
    {
        return match ($status) {
            'paid' => 'Платена',
            'unpaid' => 'Неплатена',
            'partially_paid' => 'Делумно платена',
            default => ucfirst($status),
        };
    }

    public static function movementType(string $type): string
    {
        return match ($type) {
            'receipt' => 'Прием',
            'issue' => 'Издавање',
            'transfer' => 'Трансфер',
            'adjustment' => 'Корекција',
            default => ucfirst($type),
        };
    }

    public static function vatTreatment(string $treatment): string
    {
        return match ($treatment) {
            'standard' => 'Стандардна',
            'export' => 'Извоз',
            'exempt_with_credit' => 'ослободено со право на одбивка',
            'exempt_without_credit' => 'ослободено без право на одбивка',
            default => str_replace('_', ' ', $treatment),
        };
    }

    public static function paymentMethod(string $method): string
    {
        return match ($method) {
            'bank' => 'Банка',
            'cash' => 'Готовина',
            default => ucfirst($method),
        };
    }

    public static function documentCategory(string $category): string
    {
        return match ($category) {
            'Invoice' => 'Фактура',
            'Contract' => 'Договор',
            'Bank Statement' => 'Извод од банка',
            'Receipt' => 'Сметка',
            'ID/Registration' => 'Документ за регистрација',
            'Other' => 'Друго',
            default => $category,
        };
    }

    public static function partnerType(string $type): string
    {
        return match ($type) {
            'individual' => 'Физичко лице',
            'legal_entity' => 'Правно лице',
            default => ucfirst($type),
        };
    }

    public static function itemType(string $type): string
    {
        return match ($type) {
            'product' => 'Производ',
            'service' => 'Услуга',
            default => ucfirst($type),
        };
    }
}
