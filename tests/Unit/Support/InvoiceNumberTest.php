<?php

namespace Tests\Unit\Support;

use App\Models\Company;
use App\Support\InvoiceNumber;
use Tests\TestCase;

/**
 * Форматирањето не допира база — работи врз модел во меморија, за да може
 * екранот со поставки да го користи истиот код за живиот преглед.
 */
class InvoiceNumberTest extends TestCase
{
    private function company(array $overrides = []): Company
    {
        return new Company($overrides);
    }

    public function test_the_default_settings_produce_the_old_format(): void
    {
        $this->assertSame('2026/1', InvoiceNumber::format($this->company(), 2026, 1));
    }

    public function test_the_year_can_come_after_the_number(): void
    {
        $company = $this->company(['invoice_number_year_first' => false]);

        $this->assertSame('1/2026', InvoiceNumber::format($company, 2026, 1));
    }

    public function test_it_pads_the_number_and_shortens_the_year(): void
    {
        $company = $this->company([
            'invoice_number_year_first' => false,
            'invoice_number_year_digits' => 2,
            'invoice_number_separator' => '-',
            'invoice_number_padding' => 5,
        ]);

        $this->assertSame('00001-26', InvoiceNumber::format($company, 2026, 1));
    }

    public function test_the_year_can_be_left_out_entirely(): void
    {
        $company = $this->company([
            'invoice_number_include_year' => false,
            'invoice_number_padding' => 5,
        ]);

        $this->assertSame('00001', InvoiceNumber::format($company, 2026, 1));
    }

    public function test_a_prefix_goes_in_front_of_everything(): void
    {
        $company = $this->company([
            'invoice_number_prefix' => 'ФА-',
            'invoice_number_padding' => 3,
        ]);

        $this->assertSame('ФА-2026/001', InvoiceNumber::format($company, 2026, 1));
    }

    public function test_an_empty_separator_joins_the_parts_directly(): void
    {
        $company = $this->company([
            'invoice_number_year_first' => false,
            'invoice_number_year_digits' => 2,
            'invoice_number_separator' => '',
            'invoice_number_padding' => 5,
        ]);

        $this->assertSame('0000126', InvoiceNumber::format($company, 2026, 1));
    }

    public function test_a_number_longer_than_the_padding_is_not_truncated(): void
    {
        $company = $this->company(['invoice_number_padding' => 3]);

        $this->assertSame('2026/1234', InvoiceNumber::format($company, 2026, 1234));
    }

    public function test_the_maximum_padding_is_six_digits(): void
    {
        $company = $this->company(['invoice_number_padding' => 6]);

        $this->assertSame('2026/000001', InvoiceNumber::format($company, 2026, 1));
    }

    // Вакви вредности не доаѓаат од екранот за поставки — валидацијата таму веќе
    // ги спречува. Доаѓаат од невалиден ред во базата, па класата мора сама да се
    // брани со стеснување на опсегот.

    public function test_a_padding_above_the_upper_bound_is_clamped_to_six_digits(): void
    {
        $company = $this->company(['invoice_number_padding' => 9]);

        $this->assertSame('2026/000001', InvoiceNumber::format($company, 2026, 1));
    }

    public function test_a_padding_below_the_lower_bound_is_clamped_to_one_digit(): void
    {
        $company = $this->company(['invoice_number_padding' => 0]);

        $this->assertSame('2026/1', InvoiceNumber::format($company, 2026, 1));
    }
}
