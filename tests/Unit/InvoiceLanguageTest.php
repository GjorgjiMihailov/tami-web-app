<?php

namespace Tests\Unit;

use App\Support\Format;
use App\Support\InvoiceLanguage;
use PHPUnit\Framework\TestCase;

class InvoiceLanguageTest extends TestCase
{
    public function test_macedonian_money_is_byte_identical_to_the_existing_formatter(): void
    {
        // Оваа еднаквост е единствената причина македонската фактура да остане
        // непроменета. Ако падне, печатената денарска фактура се смени.
        $this->assertSame(Format::money('1234.5'), InvoiceLanguage::MK->money('1234.5', 'MKD'));
        $this->assertSame('1.234,50 ден', InvoiceLanguage::MK->money('1234.5', 'MKD'));
    }

    public function test_macedonian_money_uses_the_currency_code_when_it_is_not_denars(): void
    {
        $this->assertSame('1.234,50 EUR', InvoiceLanguage::MK->money('1234.5', 'EUR'));
    }

    public function test_english_money_uses_english_separators_and_the_currency_code(): void
    {
        $this->assertSame('1,234.50 EUR', InvoiceLanguage::EN->money('1234.5', 'EUR'));
        $this->assertSame('0.00 USD', InvoiceLanguage::EN->money('0', 'USD'));
        $this->assertSame('1,000,000.00 CHF', InvoiceLanguage::EN->money('1000000', 'CHF'));
    }

    public function test_macedonian_date_is_byte_identical_to_the_existing_formatter(): void
    {
        $this->assertSame(Format::date('2026-09-13'), InvoiceLanguage::MK->date('2026-09-13'));
        $this->assertSame('13.09.2026', InvoiceLanguage::MK->date('2026-09-13'));
    }

    public function test_english_date_spells_the_month_so_it_cannot_be_misread(): void
    {
        // 13.09.2026 американски клиент го чита како 9 септември. Затоа месецот
        // се пишува со букви.
        $this->assertSame('13 Sep 2026', InvoiceLanguage::EN->date('2026-09-13'));
        $this->assertSame('1 Jan 2027', InvoiceLanguage::EN->date('2027-01-01'));
    }

    public function test_the_dictionary_translates_the_invoice_headings(): void
    {
        $this->assertSame('ФАКТУРА', InvoiceLanguage::MK->t('invoice'));
        $this->assertSame('INVOICE', InvoiceLanguage::EN->t('invoice'));
        $this->assertSame('Издавач', InvoiceLanguage::MK->t('seller'));
        $this->assertSame('Seller', InvoiceLanguage::EN->t('seller'));
        $this->assertSame('AUTHORISED SIGNATURE', InvoiceLanguage::EN->t('signature_issuer'));
        $this->assertSame('Not registered for VAT.', InvoiceLanguage::EN->t('not_vat_registered'));
    }

    public function test_an_unknown_key_returns_the_key_itself_instead_of_blowing_up(): void
    {
        // Полупразна фактура е полоша од фактура со чуден збор на неа, но
        // празна ќелија на печатена хартија е најлоша — затоа fallback, не грешка.
        $this->assertSame('nema_takov_kluc', InvoiceLanguage::EN->t('nema_takov_kluc'));
    }

    public function test_macedonian_vat_treatment_is_byte_identical_to_the_existing_formatter(): void
    {
        foreach (['standard', 'export', 'exempt_with_credit', 'exempt_without_credit'] as $treatment) {
            $this->assertSame(
                Format::vatTreatment($treatment),
                InvoiceLanguage::MK->vatTreatment($treatment)
            );
        }
    }

    public function test_english_vat_treatment_is_translated(): void
    {
        $this->assertSame('Export', InvoiceLanguage::EN->vatTreatment('export'));
        $this->assertSame('exempt with input tax credit', InvoiceLanguage::EN->vatTreatment('exempt_with_credit'));
        $this->assertSame('exempt without input tax credit', InvoiceLanguage::EN->vatTreatment('exempt_without_credit'));
    }

    public function test_labels_are_what_the_user_picks_from(): void
    {
        $this->assertSame('Македонски', InvoiceLanguage::MK->label());
        $this->assertSame('English', InvoiceLanguage::EN->label());
    }
}
