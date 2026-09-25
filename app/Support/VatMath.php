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

    public static function multiply(string $quantity, string $unitPrice): string
    {
        $product = bcmul(self::number($quantity), self::number($unitPrice), self::WORKING_SCALE);

        return self::round($product);
    }

    /**
     * Ставка внесена со цена БЕЗ ДДВ: основицата е количина × цена, а ДДВ-то
     * се пресметува врз неа.
     *
     * @return array{net: string, vat: string, gross: string}
     */
    public static function lineFromNet(string $quantity, string $unitNet, string $rate, string $discountPercent = '0'): array
    {
        $net = self::discounted($quantity, $unitNet, $discountPercent);
        $vat = self::vatAmount($net, $rate);

        return ['net' => $net, 'vat' => $vat, 'gross' => bcadd($net, $vat, 2)];
    }

    /**
     * Ставка внесена со цена СО ДДВ: вкупното со ДДВ е количина × таа цена и
     * тоа е бројката што мора да излезе точно. Основицата се вади наназад, а
     * ДДВ-то е разликата — така збирот никогаш не бега за стотинка.
     *
     * @return array{net: string, vat: string, gross: string}
     */
    public static function lineFromGross(string $quantity, string $unitGross, string $rate, string $discountPercent = '0'): array
    {
        $gross = self::discounted($quantity, $unitGross, $discountPercent);
        $net = self::netFromGross($gross, $rate);

        return ['net' => $net, 'vat' => bcsub($gross, $net, 2), 'gross' => $gross];
    }

    /**
     * Количина × цена, по рабат. Без рабат тоа е точно multiply() како досега,
     * па постојните износи не се менуваат ни за стотинка. Со рабат се множи
     * на работна скала и се заокружува ЕДНАШ — не прво производот па рабатот,
     * зашто две заокружувања би се разминале со една за стотинка.
     */
    public static function discounted(string $quantity, string $unitPrice, string $discountPercent): string
    {
        $discount = self::number($discountPercent);

        if (bccomp($discount, '0', self::WORKING_SCALE) <= 0) {
            return self::multiply($quantity, $unitPrice);
        }

        $product = bcmul(self::number($quantity), self::number($unitPrice), self::WORKING_SCALE);
        $factor = bcdiv(bcsub('100', $discount, self::WORKING_SCALE), '100', self::WORKING_SCALE);

        return self::round(bcmul($product, $factor, self::WORKING_SCALE));
    }

    /**
     * Цена по единица што ПОМНОЖЕНА СО КОЛИЧИНАТА ја враќа основицата.
     *
     * Кај ставка внесена со бруто цена, зачуваната нето цена (5,08) е
     * заокружена и веќе не ја дава основицата (30,51). Залихата, е-Фактура и
     * печатената фактура мора да прикажат/книжат бројка што се множи чисто,
     * па се дели наназад со четири децимали — колку што носи и `unit_cost`,
     * и колку што прикажуваат примерите на УЈП.
     */
    public static function unitPriceFromNetTotal(string $netTotal, string $quantity): string
    {
        $quantity = self::number($quantity);

        if (bccomp($quantity, '0', self::WORKING_SCALE) === 0) {
            return '0.0000';
        }

        return Bcmath::roundHalfUp(bcdiv(self::number($netTotal), $quantity, self::WORKING_SCALE), 4);
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
