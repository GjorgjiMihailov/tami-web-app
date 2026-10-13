<?php

namespace Tests\Unit\Bank;

use App\Support\Bank\LineDirection;
use App\Support\Bank\Mt940\Mt940Exception;
use App\Support\Bank\Mt940\Mt940Parser;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class Mt940ParserTest extends TestCase
{
    public function test_a_credit_line_is_read_with_both_sides_of_the_account_data(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'number' => 1,
            'date' => '2026-02-02',
            'opening' => '0.00',
            'lines' => [[
                'dir' => 'in', 'amount' => '17.00', 'party' => '100000000000002',
                'name' => 'BANKA TEST AD SKOPJE', 'refs' => '1111111111//22222222222',
                'desc' => 'Plakane po osnov uslugi', 'bankref' => '1000007',
            ]],
        ]));

        $this->assertSame(Mt940Fixture::ACCOUNT, $statement->account);
        $this->assertSame(1, $statement->number);
        $this->assertSame('2026-02-02', $statement->date);
        $this->assertSame('0.00', $statement->opening);
        $this->assertSame('17.00', $statement->closing);
        $this->assertSame('TESTMK2X', $statement->bic);
        $this->assertSame('0.00', $statement->difference());
        $this->assertCount(1, $statement->lines);

        $line = $statement->lines[0];
        $this->assertSame('2026-02-02', $line->date);
        $this->assertSame(LineDirection::IN, $line->direction);
        $this->assertSame('17.00', $line->amount);
        $this->assertSame('100000000000002', $line->counterpartyAccount);
        $this->assertSame('BANKA TEST AD SKOPJE', $line->counterpartyName);
        $this->assertSame('1111111111//22222222222', $line->referenceNumber);
        $this->assertSame('Plakane po osnov uslugi', $line->description);
        $this->assertSame('1000007', $line->bankReference);
    }

    public function test_a_debit_line_is_an_outgoing_payment(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'opening' => '1000.00',
            'lines' => [['dir' => 'out', 'amount' => '450.00', 'bankref' => '1000008']],
        ]));

        $line = $statement->lines[0];
        $this->assertSame(LineDirection::OUT, $line->direction);
        $this->assertSame('450.00', $line->amount);
        $this->assertSame('-450.00', $line->signedAmount());
        $this->assertSame('1000008', $line->bankReference);
        $this->assertSame('550.00', $statement->closing);
        $this->assertSame('-450.00', $statement->movement());
    }

    public function test_amounts_with_and_without_decimals_are_understood(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'lines' => [
                ['dir' => 'in', 'amount' => '17.50'],
                ['dir' => 'in', 'amount' => '123456.00'],
                ['dir' => 'in', 'amount' => '0.05'],
            ],
        ]));

        $this->assertSame(['17.50', '123456.00', '0.05'], array_map(fn ($l) => $l->amount, $statement->lines));
        $this->assertSame('123473.55', $statement->closing);
    }

    public function test_a_debit_balance_is_negative(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'opening' => '100.00',
            'lines' => [['dir' => 'out', 'amount' => '150.00']],
        ]));

        $this->assertSame('-50.00', $statement->closing);
        $this->assertSame('0.00', $statement->difference());
    }

    public function test_a_file_saved_by_windows_reads_the_same(): void
    {
        $lines = [['dir' => 'in', 'amount' => '10.00']];
        $clean = Mt940Parser::parse(Mt940Fixture::statement(['lines' => $lines]));
        $windows = Mt940Parser::parse("\xEF\xBB\xBF".Mt940Fixture::statement(['lines' => $lines, 'eol' => "\r\n"]));

        $this->assertEquals($clean, $windows);
    }

    public function test_a_file_without_the_swift_envelope_is_read(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'envelope' => false,
            'lines' => [['dir' => 'in', 'amount' => '10.00']],
        ]));

        $this->assertNull($statement->bic);
        $this->assertCount(1, $statement->lines);
    }

    public function test_padded_fields_are_trimmed_and_zero_only_references_are_dropped(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'lines' => [[
                'dir' => 'in', 'amount' => '10.00',
                'name' => 'MARKO TESTOV                       ',
                'refs' => '0                       //0                       ',
                'desc' => 'Ostanati plakana0                     ',
            ]],
        ]));

        $line = $statement->lines[0];
        $this->assertSame('MARKO TESTOV', $line->counterpartyName);
        $this->assertNull($line->referenceNumber);
        $this->assertSame('Ostanati plakana0', $line->description);
    }

    public function test_a_description_longer_than_the_column_is_cut(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'lines' => [['dir' => 'in', 'amount' => '10.00', 'desc' => str_repeat('x', 400), 'name' => str_repeat('N', 400)]],
        ]));

        $this->assertSame(255, mb_strlen($statement->lines[0]->description));
        $this->assertSame(255, mb_strlen((string) $statement->lines[0]->counterpartyName));
    }

    public function test_an_unstructured_86_becomes_the_description_and_the_other_side_is_unknown(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'lines' => [['dir' => 'in', 'amount' => '10.00', 'raw86' => "Prenos na sredstva\nmeki redovi"]],
        ]));

        $line = $statement->lines[0];
        $this->assertNull($line->counterpartyAccount);
        $this->assertNull($line->counterpartyName);
        $this->assertSame('Prenos na sredstva meki redovi', $line->description);
    }

    public function test_a_line_without_86_is_kept_and_twin_lines_are_not_merged(): void
    {
        $statement = Mt940Parser::parse(Mt940Fixture::statement([
            'lines' => [
                ['dir' => 'in', 'amount' => '10.00', 'no86' => true],
                ['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002', 'name' => 'TWIN'],
                ['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002', 'name' => 'TWIN'],
            ],
        ]));

        $this->assertCount(3, $statement->lines);
        $this->assertSame('', $statement->lines[0]->description);
        $this->assertNull($statement->lines[0]->counterpartyAccount);
        $this->assertSame('30.00', $statement->movement());
    }

    public function test_a_foreign_currency_statement_is_refused(): void
    {
        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('MKD');

        Mt940Parser::parse(Mt940Fixture::statement(['currency' => 'EUR']));
    }

    public function test_interim_balances_are_refused(): void
    {
        $text = str_replace(':62F:', ":60M:C260202MKD5,\n:62F:", Mt940Fixture::statement());

        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('меѓусостојби');

        Mt940Parser::parse($text);
    }

    public function test_two_statements_in_one_file_are_refused(): void
    {
        $one = Mt940Fixture::statement();

        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('повеќе од еден извод');

        Mt940Parser::parse($one."\n".$one);
    }

    public function test_a_reversal_is_refused(): void
    {
        $text = preg_replace('/(:61:\d{10})CD/', '$1RC', Mt940Fixture::statement([
            'lines' => [['dir' => 'in', 'amount' => '10.00']],
        ]));

        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('Сторно');

        Mt940Parser::parse($text);
    }

    public function test_an_amount_with_a_thousands_separator_refuses_the_whole_file(): void
    {
        $text = str_replace('CD10,', 'CD1.234,56', Mt940Fixture::statement([
            'lines' => [['dir' => 'in', 'amount' => '10.00']],
        ]));

        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('Неважечка ставка');

        Mt940Parser::parse($text);
    }

    public function test_a_missing_account_field_is_refused(): void
    {
        $text = preg_replace('/^:25:.*\n/m', '', Mt940Fixture::statement());

        $this->expectException(Mt940Exception::class);
        $this->expectExceptionMessage('недостасува поле :25:');

        Mt940Parser::parse($text);
    }
}
