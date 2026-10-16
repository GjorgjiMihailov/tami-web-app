<?php

namespace Tests\Feature\Invoicing;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Services\Invoicing\SalesInvoiceImportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\TestCase;

class SalesInvoiceImportParserTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Број на фактура', 'Датум на издавање', 'Кооперант', 'ЕДБ на кооперант', 'Вкупно од хартија (незадолжително)'];

    private function parse(Company $company, array $rows): array
    {
        return app(SalesInvoiceImportParser::class)->parse($rows, $company->id);
    }

    public function test_a_partner_is_matched_by_tax_id_ignoring_surrounding_spaces(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Прва ДООЕЛ', 'tax_id' => '4043025542148']);

        $result = $this->parse($company, [['00001-26', '04.01.2026', 'Различно име', '  4043025542148 ', '2360']]);

        $this->assertSame('ready', $result[0]['status']);
        $this->assertSame($partner->id, $result[0]['partner_id']);
    }

    public function test_a_partner_is_matched_by_name_ignoring_case_and_extra_spaces(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'ДЕЛТА ЛИМИТЕД ДООЕЛ']);

        $result = $this->parse($company, [['00005-26', '04.01.2026', '  Делта   Лимитед ДООЕЛ ', '', '']]);

        $this->assertSame('ready', $result[0]['status']);
        $this->assertSame($partner->id, $result[0]['partner_id']);
    }

    public function test_a_tax_id_typed_as_a_number_in_excel_is_still_matched(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create(['name' => 'Прва ДООЕЛ', 'tax_id' => '4043025542148']);

        // Excel ги враќа големите цели броеви како децимален број.
        $result = $this->parse($company, [['00001-26', '04.01.2026', 'Некое име', 4043025542148.0, '']]);

        $this->assertSame('4043025542148', $result[0]['tax_id']);
        $this->assertSame($partner->id, $result[0]['partner_id']);
    }

    public function test_a_name_shared_by_two_partners_stays_unmatched(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->count(2)->create(['name' => 'Исто Име', 'tax_id' => null]);

        $result = $this->parse($company, [['00001-26', '04.01.2026', 'Исто Име', '', '']]);

        $this->assertSame('unmatched', $result[0]['status']);
        $this->assertNull($result[0]['partner_id']);
    }

    public function test_a_partner_of_another_company_is_never_matched(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->create(['name' => 'Туѓа Фирма', 'tax_id' => '1111111111111']);

        $result = $this->parse($company, [['00001-26', '04.01.2026', 'Туѓа Фирма', '1111111111111', '']]);

        $this->assertSame('unmatched', $result[0]['status']);
    }

    public function test_dates_are_read_as_text_iso_and_excel_serials(): void
    {
        $company = Company::factory()->create();
        $serial = Date::dateTimeToExcel(new \DateTime('2026-01-04'));

        $result = $this->parse($company, [
            ['1', '04.01.2026', 'А', '', ''],
            ['2', '2026-01-04', 'А', '', ''],
            ['3', '04/01/2026', 'А', '', ''],
            ['4', $serial, 'А', '', ''],
            ['5', (string) $serial, 'А', '', ''],
        ]);

        foreach ($result as $row) {
            $this->assertSame('2026-01-04', $row['date'], 'Ред '.$row['row_number']);
            $this->assertSame([], $row['errors']);
        }
    }

    public function test_two_digit_years_and_impossible_dates_are_errors(): void
    {
        $company = Company::factory()->create();

        $result = $this->parse($company, [
            ['1', '4.1.26', 'А', '', ''],
            ['2', '31.02.2026', 'А', '', ''],
            ['3', 'вчера', 'А', '', ''],
            ['4', '', 'А', '', ''],
        ]);

        foreach ($result as $row) {
            $this->assertSame('error', $row['status']);
            $this->assertNull($row['date']);
            $this->assertNotEmpty($row['errors']);
        }
    }

    public function test_totals_are_normalised_and_bad_ones_are_errors(): void
    {
        $company = Company::factory()->create();

        $result = $this->parse($company, [
            ['1', '04.01.2026', 'А', '', '2360,00'],
            ['2', '04.01.2026', 'А', '', 2360],
            ['3', '04.01.2026', 'А', '', ' 2 360.5 '],
            ['4', '04.01.2026', 'А', '', ''],
            ['5', '04.01.2026', 'А', '', '2.360,00'],
            ['6', '04.01.2026', 'А', '', 'илјада'],
            ['7', '04.01.2026', 'А', '', '12.345'],
        ]);

        $this->assertSame('2360.00', $result[0]['total']);
        $this->assertSame('2360.00', $result[1]['total']);
        $this->assertSame('2360.50', $result[2]['total']);
        $this->assertNull($result[3]['total']);
        $this->assertSame([], $result[3]['errors']);
        $this->assertSame('error', $result[4]['status']);
        $this->assertSame('error', $result[5]['status']);
        // Три децимали не се заокружуваат тивко — износот од хартија има најмногу две.
        $this->assertSame('error', $result[6]['status']);
    }

    public function test_a_missing_number_or_partner_is_an_error(): void
    {
        $company = Company::factory()->create();

        $result = $this->parse($company, [
            ['', '04.01.2026', 'А', '', ''],
            ['1', '04.01.2026', '', '', ''],
        ]);

        $this->assertSame('error', $result[0]['status']);
        $this->assertSame('error', $result[1]['status']);
    }

    public function test_a_number_that_already_exists_in_that_year_is_an_error(): void
    {
        $company = Company::factory()->create();
        SalesInvoice::factory()->for($company)->create([
            'invoice_number_formatted' => '00001-26',
            'invoice_date' => '2026-01-02',
            'due_date' => '2026-01-02',
        ]);

        $result = $this->parse($company, [
            ['00001-26', '04.01.2026', 'А', '', ''],
            ['00001-26', '04.01.2027', 'А', '', ''], // иста ознака, друга година — дозволено
        ]);

        $this->assertSame('error', $result[0]['status']);
        $this->assertNotSame('error', $result[1]['status']);
    }

    public function test_a_number_repeated_in_the_sheet_is_an_error_on_the_second_row(): void
    {
        $company = Company::factory()->create();

        $result = $this->parse($company, [
            ['00001-26', '04.01.2026', 'А', '', ''],
            ['00001-26', '05.01.2026', 'Б', '', ''],
            ['00001-27', '05.01.2027', 'Б', '', ''],
        ]);

        $this->assertNotSame('error', $result[0]['status']);
        $this->assertSame('error', $result[1]['status']);
        $this->assertStringContainsString('ред 1', $result[1]['errors'][0]);
        $this->assertNotSame('error', $result[2]['status']);
    }

    public function test_a_number_is_taken_as_it_arrives_and_never_repaired(): void
    {
        $company = Company::factory()->create();

        // Excel што го претворил „00001" во број 1 — парсерот не погодува нули.
        $result = $this->parse($company, [[1, '04.01.2026', 'А', '', '']]);

        $this->assertSame('1', $result[0]['number']);
    }

    public function test_the_header_and_empty_rows_are_skipped_and_row_numbers_stay_true(): void
    {
        $company = Company::factory()->create();

        $result = $this->parse($company, [
            self::HEADER,
            ['00001-26', '04.01.2026', 'А', '', ''],
            [null, null, null, null, null],
            ['', '', '', '', ''],
            ['00002-26', '05.01.2026', 'Б', '', ''],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame(2, $result[0]['row_number']);
        $this->assertSame(5, $result[1]['row_number']);
    }

    public function test_more_than_500_rows_are_refused(): void
    {
        $company = Company::factory()->create();
        $rows = [];

        for ($i = 1; $i <= 501; $i++) {
            $rows[] = [(string) $i, '04.01.2026', 'А', '', ''];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Најмногу 500 редови по прикачување.');

        $this->parse($company, $rows);
    }

    public function test_exactly_500_rows_are_accepted(): void
    {
        $company = Company::factory()->create();
        $rows = [];

        for ($i = 1; $i <= 500; $i++) {
            $rows[] = [(string) $i, '04.01.2026', 'А', '', ''];
        }

        $this->assertCount(500, $this->parse($company, $rows));
    }
}
