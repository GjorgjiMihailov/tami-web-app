<?php

namespace Tests\Unit;

use App\Support\Bank\BankLinePattern;
use PHPUnit\Framework\TestCase;

class BankLinePatternTest extends TestCase
{
    public function test_the_same_kind_of_text_gives_the_same_pattern_whatever_the_dates_and_ordinals(): void
    {
        $a = BankLinePattern::from('Prov.PP (nal.+gen.izvod) za treta dekada za 07/2026');
        $b = BankLinePattern::from('Prov.PP (nal.+gen.izvod) za vtora dekada za 08/2026');
        $c = BankLinePattern::from('Prov.PP (nal.+gen.izvod) za prva dekada za 03');

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        $this->assertSame('prov pp nal gen izvod za # dekada za #', $a);
    }

    public function test_months_by_number_or_by_name_are_the_same(): void
    {
        $this->assertSame(
            BankLinePattern::from('Mesecna provizija za mesec 02/2026'),
            BankLinePattern::from('Mesecna provizija za mesec 11/2026'),
        );
        $this->assertSame(
            BankLinePattern::from('Mesecen nadomest za oktomvri'),
            BankLinePattern::from('Mesecen nadomest za januari'),
        );
    }

    public function test_different_kinds_of_text_stay_apart(): void
    {
        $this->assertNotSame(
            BankLinePattern::from('Mesecna provizija za mesec 02/2026'),
            BankLinePattern::from('Prov.PP (nal.+gen.izvod) za treta dekada za 07'),
        );
        $this->assertNotSame(
            BankLinePattern::from('Otkup EUR 2.950.00'),
            BankLinePattern::from('Provizija po doznaka2,950.00 EUR'),
        );
    }

    public function test_amounts_and_runs_of_digits_collapse_to_one_marker(): void
    {
        $this->assertSame('otkup eur #', BankLinePattern::from('Otkup EUR 2.950.00'));
        $this->assertSame('faktura #', BankLinePattern::from('faktura 00004-26'));
    }

    public function test_text_without_meaning_has_no_pattern(): void
    {
        $this->assertNull(BankLinePattern::from('000'));
        $this->assertNull(BankLinePattern::from('300'));
        $this->assertNull(BankLinePattern::from('  '));
        $this->assertNull(BankLinePattern::from(null));
        $this->assertNull(BankLinePattern::from('1155/26'));
    }

    public function test_a_long_text_is_cut_to_eighty_characters(): void
    {
        $pattern = BankLinePattern::from(str_repeat('plakane na sredstva ', 20));

        $this->assertNotNull($pattern);
        $this->assertLessThanOrEqual(80, mb_strlen($pattern));
    }
}
