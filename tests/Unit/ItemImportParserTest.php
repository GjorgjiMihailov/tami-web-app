<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Item;
use App\Services\Inventory\ItemImportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemImportParserTest extends TestCase
{
    use RefreshDatabase;

    private ItemImportParser $parser;

    public function setUp(): void
    {
        parent::setUp();
        $this->parser = new ItemImportParser();
    }

    private const HEADER = ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'];

    public function test_a_complete_new_row_parses_as_new_with_all_fields(): void
    {
        $company = Company::factory()->create();
        $rows = [
            self::HEADER,
            ['SKU-1', 'Widget', 'парче', 'Алат', '18', '99.90', 'производ', 'Да', '3800000000017'],
        ];

        $result = $this->parser->parse($rows, $company->id);

        $this->assertCount(1, $result);
        $row = $result[0];
        $this->assertSame('new', $row['action']);
        $this->assertSame([], $row['errors']);
        $this->assertSame('SKU-1', $row['code']);
        $this->assertSame('Widget', $row['name']);
        $this->assertSame('парче', $row['unit_of_measure']);
        $this->assertSame('Алат', $row['category']);
        $this->assertSame('18.00', $row['vat_rate']);
        $this->assertSame('99.90', $row['selling_price']);
        $this->assertSame('product', $row['type']);
        $this->assertTrue($row['is_made_in_mk']);
        $this->assertSame('3800000000017', $row['barcode']);
        $this->assertNull($row['existing_item_id']);
    }

    public function test_a_minimal_new_row_gets_sensible_defaults(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-2', 'Widget 2', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('new', $row['action']);
        $this->assertSame('18.00', $row['vat_rate']);
        $this->assertSame('product', $row['type']);
        $this->assertFalse($row['is_made_in_mk']);
        $this->assertNull($row['category']);
        $this->assertNull($row['selling_price']);
        $this->assertNull($row['barcode']);
    }

    public function test_a_row_matching_an_existing_code_is_marked_as_update(): void
    {
        $company = Company::factory()->create();
        $existing = Item::factory()->for($company)->create(['code' => 'SKU-3']);
        $rows = [self::HEADER, ['SKU-3', 'New Name', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('update', $row['action']);
        $this->assertSame($existing->id, $row['existing_item_id']);
    }

    public function test_blank_optional_cells_on_an_update_row_mean_keep_existing(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-4', 'vat_rate' => '5.00', 'type' => 'service', 'is_made_in_mk' => true]);
        $rows = [self::HEADER, ['SKU-4', 'Renamed', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('update', $row['action']);
        $this->assertNull($row['vat_rate']);
        $this->assertNull($row['type']);
        $this->assertNull($row['is_made_in_mk']);
        $this->assertFalse($row['category_provided']);
        $this->assertFalse($row['selling_price_provided']);
        $this->assertFalse($row['barcode_provided']);
    }

    public function test_a_provided_value_on_an_update_row_overwrites(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-5', 'vat_rate' => '5.00']);
        $rows = [self::HEADER, ['SKU-5', 'Renamed', 'парче', '', '18', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('18.00', $row['vat_rate']);
    }

    public function test_blank_code_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['', 'Widget', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
        $this->assertContains('Шифрата е задолжителна.', $row['errors']);
    }

    public function test_duplicate_code_within_the_file_is_an_error_on_the_second_occurrence(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-6', 'A', 'парче', '', '', '', '', '', ''], ['SKU-6', 'B', 'парче', '', '', '', '', '', '']];

        $result = $this->parser->parse($rows, $company->id);

        $this->assertSame('new', $result[0]['action']);
        $this->assertSame('error', $result[1]['action']);
    }

    public function test_blank_name_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-7', '', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
        $this->assertContains('Називот е задолжителен.', $row['errors']);
    }

    public function test_blank_unit_of_measure_is_an_error_only_for_new_rows(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-8', 'unit_of_measure' => 'kg']);
        $rows = [
            self::HEADER,
            ['SKU-8', 'Existing', '', '', '', '', '', '', ''],
            ['SKU-9', 'New', '', '', '', '', '', '', ''],
        ];

        $result = $this->parser->parse($rows, $company->id);

        $this->assertSame('update', $result[0]['action']);
        $this->assertSame('error', $result[1]['action']);
        $this->assertContains('Мерната единица е задолжителна за нов артикл.', $result[1]['errors']);
    }

    public function test_invalid_vat_rate_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-10', 'Widget', 'парче', '', 'not-a-number', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
        $this->assertContains('Стапката на ДДВ мора да биде број од 0 до 100.', $row['errors']);
    }

    public function test_vat_rate_out_of_range_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-11', 'Widget', 'парче', '', '150', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
    }

    public function test_negative_selling_price_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-12', 'Widget', 'парче', '', '', '-5', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
        $this->assertContains('Продажната цена мора да биде позитивен број.', $row['errors']);
    }

    public function test_invalid_type_value_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-13', 'Widget', 'парче', '', '', '', 'нешто-друго', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
        $this->assertStringContainsString('тип', $row['errors'][0]);
    }

    public function test_type_value_is_case_insensitive(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-14', 'Widget', 'парче', '', '', '', 'УСЛУГА', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('service', $row['type']);
    }

    public function test_invalid_made_in_mk_value_is_an_error(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-15', 'Widget', 'парче', '', '', '', '', 'можеби', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
    }

    public function test_duplicate_barcode_within_the_file_is_an_error_on_the_second_occurrence(): void
    {
        $company = Company::factory()->create();
        $rows = [
            self::HEADER,
            ['SKU-16', 'A', 'парче', '', '', '', '', '', '3800000000017'],
            ['SKU-17', 'B', 'парче', '', '', '', '', '', '3800000000017'],
        ];

        $result = $this->parser->parse($rows, $company->id);

        $this->assertSame('new', $result[0]['action']);
        $this->assertSame('error', $result[1]['action']);
    }

    public function test_barcode_already_used_by_another_item_is_an_error(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-18', 'barcode' => '3800000000017']);
        $rows = [self::HEADER, ['SKU-19', 'New Item', 'парче', '', '', '', '', '', '3800000000017']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('error', $row['action']);
    }

    public function test_updating_an_item_with_its_own_unchanged_barcode_is_not_an_error(): void
    {
        $company = Company::factory()->create();
        Item::factory()->for($company)->create(['code' => 'SKU-20', 'barcode' => '3800000000017']);
        $rows = [self::HEADER, ['SKU-20', 'Renamed', 'парче', '', '', '', '', '', '3800000000017']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame('update', $row['action']);
    }

    public function test_blank_rows_are_skipped(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['', '', '', '', '', '', '', '', ''], ['SKU-21', 'Widget', 'парче', '', '', '', '', '', '']];

        $result = $this->parser->parse($rows, $company->id);

        $this->assertCount(1, $result);
        $this->assertSame('SKU-21', $result[0]['code']);
    }

    public function test_row_numbers_match_the_spreadsheets_own_1_based_row_number(): void
    {
        $company = Company::factory()->create();
        $rows = [self::HEADER, ['SKU-22', 'Widget', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $company->id)[0];

        $this->assertSame(2, $row['row_number']);
    }

    public function test_a_row_cannot_conflict_with_another_companys_item_code(): void
    {
        $otherCompany = Company::factory()->create();
        $thisCompany = Company::factory()->create();
        Item::factory()->for($otherCompany)->create(['code' => 'SKU-23']);
        $rows = [self::HEADER, ['SKU-23', 'Widget', 'парче', '', '', '', '', '', '']];

        $row = $this->parser->parse($rows, $thisCompany->id)[0];

        $this->assertSame('new', $row['action']);
    }
}
