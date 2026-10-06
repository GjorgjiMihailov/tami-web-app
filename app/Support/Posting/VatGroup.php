<?php

namespace App\Support\Posting;

/** Даночна група на ставка: одредува на кое конто оди приходот и ДДВ-то. */
enum VatGroup: string
{
    case GENERAL = 'general';
    case REDUCED = 'reduced';
    case EXEMPT_WITH_CREDIT = 'exempt_with_credit';
    case EXEMPT_WITHOUT_CREDIT = 'exempt_without_credit';
    case EXPORT = 'export';

    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'Општа стапка (18%)',
            self::REDUCED => 'Повластена стапка (5%/10%)',
            self::EXEMPT_WITH_CREDIT => 'Ослободено со право на одбивка',
            self::EXEMPT_WITHOUT_CREDIT => 'Ослободено без право на одбивка',
            self::EXPORT => 'Извоз',
        };
    }

    /** Третманот на ставката (`vat_treatment`) и стапката ја одредуваат групата. */
    public static function forLine(string $treatment, string $rate): self
    {
        return match ($treatment) {
            'export' => self::EXPORT,
            'exempt_with_credit' => self::EXEMPT_WITH_CREDIT,
            'exempt_without_credit' => self::EXEMPT_WITHOUT_CREDIT,
            default => (bccomp($rate, '5', 2) === 0 || bccomp($rate, '10', 2) === 0) ? self::REDUCED : self::GENERAL,
        };
    }
}
