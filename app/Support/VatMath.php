<?php

namespace App\Support;

/**
 * VAT arithmetic shared by the purchase invoice form and its posting service,
 * so what the user sees while typing is what actually gets booked.
 *
 * Everything is bcmath strings rounded half up — never bcadd's truncation —
 * and anything that is not a usable number counts as zero rather than
 * throwing, because these run on every keystroke in a half-filled form.
 */
class VatMath
{
    private const WORKING_SCALE = 10;

    public static function lineNet(string $quantity, string $unitPrice): string
    {
        $product = bcmul(self::number($quantity), self::number($unitPrice), self::WORKING_SCALE);

        return self::round($product);
    }

    public static function vatAmount(string $net, string $rate): string
    {
        $fraction = bcdiv(self::number($rate), '100', self::WORKING_SCALE);

        return self::round(bcmul(self::number($net), $fraction, self::WORKING_SCALE));
    }

    public static function grossFromNet(string $net, string $rate): string
    {
        return self::round(bcmul(self::number($net), self::multiplier($rate), self::WORKING_SCALE));
    }

    public static function netFromGross(string $gross, string $rate): string
    {
        $multiplier = self::multiplier($rate);

        if (bccomp($multiplier, '0', self::WORKING_SCALE) === 0) {
            return '0.00';
        }

        return self::round(bcdiv(self::number($gross), $multiplier, self::WORKING_SCALE));
    }

    private static function multiplier(string $rate): string
    {
        return bcadd('1', bcdiv(self::number($rate), '100', self::WORKING_SCALE), self::WORKING_SCALE);
    }

    private static function number(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));

        return is_numeric($value) ? $value : '0';
    }

    private static function round(string $value): string
    {
        $rounded = Bcmath::roundHalfUp($value, 2);

        // Rounding a tiny negative leaves bcmath's "-0.00"; nobody wants to read that.
        return $rounded === '-0.00' ? '0.00' : $rounded;
    }
}
