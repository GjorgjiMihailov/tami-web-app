<?php

namespace Tests\Unit;

use App\Support\Bank\InvoiceNumberText;
use App\Support\Bank\NameSkeleton;
use PHPUnit\Framework\TestCase;

class InvoiceNumberTextTest extends TestCase
{
    public function test_a_number_is_found_in_the_four_usual_spellings(): void
    {
        $this->assertTrue(InvoiceNumberText::contains('fra 00004-26', '00004-26'));
        $this->assertTrue(InvoiceNumberText::contains('faktura 4/26', '00004-26'));
        $this->assertTrue(InvoiceNumberText::contains('Uplata po faktura br. 08528/2026-NP', '08528/2026-NP'));
        $this->assertTrue(InvoiceNumberText::contains('faktura 2600000286', '2600000286'));
    }

    public function test_a_number_glued_to_other_digits_is_not_found(): void
    {
        $this->assertFalse(InvoiceNumberText::contains('uplata 1000042 6', '00004-26'));
        $this->assertFalse(InvoiceNumberText::contains('faktura 12600000286', '2600000286'));
    }

    public function test_a_number_must_come_as_a_whole_sequence(): void
    {
        $this->assertFalse(InvoiceNumberText::contains('rok 26', '00004-26'));
        $this->assertFalse(InvoiceNumberText::contains('fra 00004', '00004-26'));
    }

    public function test_a_number_with_fewer_than_three_significant_characters_is_never_searched(): void
    {
        $this->assertFalse(InvoiceNumberText::contains('faktura 12', '12'));
        $this->assertFalse(InvoiceNumberText::contains('plakane', ''));
    }

    public function test_names_in_cyrillic_and_bank_latin_have_the_same_skeleton(): void
    {
        $this->assertTrue(NameSkeleton::same('Ѓорѓи Михаилов', 'GORGI MIHAILOV'));
        $this->assertTrue(NameSkeleton::same('Транслог ПНЛ ДООЕЛ Скопје', 'PNL TRANSLOG DOOEL Skopje'));
        $this->assertTrue(NameSkeleton::same('Јована Мичевска Митев', 'JOVANA MICEVSKA MITEV'));
    }

    public function test_different_names_or_only_short_words_do_not_match(): void
    {
        $this->assertFalse(NameSkeleton::same('Ѓорѓи Михаилов', 'GORGI PETROVSKI'));
        $this->assertFalse(NameSkeleton::same('АБ ДООЕЛ', 'AB DOOEL'));
        $this->assertFalse(NameSkeleton::same(null, 'GORGI'));
    }
}
