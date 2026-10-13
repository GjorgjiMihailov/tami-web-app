<?php

namespace Tests\Feature\Bank;

use App\Models\BankStatement;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\User;
use App\Services\Bank\Mt940Importer;
use App\Services\Bank\Mt940ImportOutcome;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\Mt940\Mt940Statement;
use App\Support\BankStatementKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class Mt940ImporterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        $this->company = Company::factory()->create();
    }

    /** @param  array<string, mixed>  $options */
    private function importOne(array $options, ?Company $company = null): Mt940ImportOutcome
    {
        return app(Mt940Importer::class)->importMany($company ?? $this->company, [
            ['name' => 'izvod.txt', 'text' => Mt940Fixture::statement($options)],
        ])[0];
    }

    /**
     * @param  list<array<string, mixed>>  $optionSets
     * @return list<Mt940ImportOutcome>
     */
    private function importBatch(array $optionSets): array
    {
        return app(Mt940Importer::class)->importMany($this->company, array_map(
            fn (array $options, int $i) => ['name' => "izvod-{$i}.txt", 'text' => Mt940Fixture::statement($options)],
            $optionSets,
            array_keys($optionSets),
        ));
    }

    public function test_a_statement_is_created_with_its_lines_and_balances(): void
    {
        $outcome = $this->importOne([
            'bic' => 'KOBSMK2X', 'number' => 7, 'date' => '2026-03-04', 'opening' => '5000.00',
            'lines' => [
                ['dir' => 'out', 'amount' => '7800.00', 'party' => '100000000000009', 'name' => 'CARINSKA TEST', 'refs' => 'A1//B2', 'desc' => 'uplata po ECD'],
                ['dir' => 'in', 'amount' => '8000.00', 'party' => '100000000000008', 'name' => 'LICE TEST', 'desc' => 'pozajmica'],
            ],
        ]);

        $this->assertTrue($outcome->ok);
        $this->assertSame(2, $outcome->lines);

        $statement = BankStatement::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($statement->id, $outcome->statementId);
        $this->assertSame(7, $statement->number);
        $this->assertSame('Комерцијална банка АД Скопје', $statement->bank);
        $this->assertSame(Mt940Fixture::ACCOUNT, $statement->account);
        $this->assertSame(BankStatementKind::DENAR, $statement->kind);
        $this->assertSame('2026-03-04', $statement->statement_date->toDateString());
        $this->assertSame('5000.00', $statement->opening_balance);
        $this->assertSame('5200.00', $statement->closing_balance);
        $this->assertSame(BankStatement::STATUS_DRAFT, $statement->status);
        $this->assertSame($this->admin->id, $statement->uploaded_by);

        $lines = $statement->lines;
        $this->assertCount(2, $lines);
        $this->assertSame(LineDirection::OUT, $lines[0]->direction);
        $this->assertSame('7800.00', $lines[0]->amount);
        $this->assertSame(LineKind::UNCLEAR, $lines[0]->kind);
        $this->assertSame(\App\Support\Bank\MatchConfidence::UNCLEAR, $lines[0]->match_confidence);
        $this->assertNull($lines[0]->reviewed_at);
        $this->assertNull($lines[0]->partner_id);
        $this->assertSame('100000000000009', $lines[0]->counterparty_account);
        $this->assertSame('CARINSKA TEST', $lines[0]->counterparty_name);
        $this->assertSame('A1//B2', $lines[0]->reference_number);
        $this->assertSame('uplata po ECD', $lines[0]->description);
        $this->assertSame(LineDirection::IN, $lines[1]->direction);
    }

    public function test_the_bank_name_from_the_company_profile_wins(): void
    {
        CompanyBankAccount::create([
            'company_id' => $this->company->id, 'bank_name' => 'Профилна банка', 'account_number' => Mt940Fixture::ACCOUNT,
        ]);

        $this->importOne(['bic' => 'KOBSMK2X']);

        $this->assertSame('Профилна банка', BankStatement::firstOrFail()->bank);
    }

    public function test_the_same_statement_cannot_be_imported_twice(): void
    {
        $this->importOne(['number' => 1]);
        $second = $this->importOne(['number' => 1]);

        $this->assertFalse($second->ok);
        $this->assertStringContainsString('веќе постои', $second->message);
        $this->assertSame(1, BankStatement::count());
    }

    public function test_another_companys_account_is_refused_and_nothing_is_created(): void
    {
        $other = Company::factory()->create();
        CompanyBankAccount::create([
            'company_id' => $other->id, 'bank_name' => 'Туѓа', 'account_number' => Mt940Fixture::ACCOUNT,
        ]);

        $outcome = $this->importOne(['number' => 1]);

        $this->assertFalse($outcome->ok);
        $this->assertStringContainsString('друга фирма', $outcome->message);
        $this->assertSame(0, BankStatement::count());
    }

    public function test_an_account_that_has_statements_in_another_company_is_refused(): void
    {
        BankStatement::factory()->create(['account' => Mt940Fixture::ACCOUNT, 'number' => 1]);

        $outcome = $this->importOne(['number' => 2]);

        $this->assertFalse($outcome->ok);
        $this->assertStringContainsString('друга фирма', $outcome->message);
    }

    public function test_an_account_missing_from_the_profile_is_a_warning(): void
    {
        $outcome = $this->importOne(['lines' => [['dir' => 'in', 'amount' => '10.00']]]);

        $this->assertTrue($outcome->ok);
        $this->assertTrue(collect($outcome->warnings)->contains(fn ($w) => str_contains($w, 'профилот')));
    }

    public function test_a_known_account_in_the_profile_gives_no_account_warning(): void
    {
        CompanyBankAccount::create([
            'company_id' => $this->company->id, 'bank_name' => 'Банка', 'account_number' => Mt940Fixture::ACCOUNT,
        ]);

        $outcome = $this->importOne(['lines' => [['dir' => 'in', 'amount' => '10.00']]]);

        $this->assertSame([], $outcome->warnings);
    }

    public function test_a_difference_in_the_balances_is_a_warning_not_a_refusal(): void
    {
        $outcome = $this->importOne([
            'opening' => '0.00', 'closing' => '50.00',
            'lines' => [['dir' => 'in', 'amount' => '100.00']],
        ]);

        $this->assertTrue($outcome->ok);
        $this->assertTrue(collect($outcome->warnings)->contains(fn ($w) => str_contains($w, 'разлика')));
    }

    public function test_a_broken_chain_is_a_warning_not_a_refusal(): void
    {
        $this->importOne(['number' => 1, 'opening' => '0.00', 'lines' => [['dir' => 'in', 'amount' => '100.00']]]);
        $second = $this->importOne([
            'number' => 2, 'date' => '2026-02-11', 'opening' => '90.00',
            'lines' => [['dir' => 'in', 'amount' => '10.00']],
        ]);

        $this->assertTrue($second->ok);
        $this->assertTrue(collect($second->warnings)->contains(fn ($w) => str_contains($w, 'не е еднаква')));
    }

    public function test_files_are_imported_in_date_order_whatever_the_upload_order(): void
    {
        $outcomes = $this->importBatch([
            ['number' => 3, 'date' => '2026-02-12', 'opening' => '150.00', 'lines' => [['dir' => 'in', 'amount' => '50.00']]],
            ['number' => 1, 'date' => '2026-02-02', 'opening' => '0.00', 'lines' => [['dir' => 'in', 'amount' => '100.00']]],
            ['number' => 2, 'date' => '2026-02-11', 'opening' => '100.00', 'lines' => [['dir' => 'in', 'amount' => '50.00']]],
        ]);

        $this->assertCount(3, $outcomes);
        foreach ($outcomes as $outcome) {
            $this->assertTrue($outcome->ok, $outcome->message);
            $this->assertFalse(collect($outcome->warnings)->contains(fn ($w) => str_contains($w, 'не е еднаква')), 'Лажен прекин на ланец.');
        }
        $this->assertSame(3, BankStatement::count());
    }

    public function test_one_bad_file_does_not_stop_the_rest(): void
    {
        $outcomes = $this->importBatch([
            ['currency' => 'EUR', 'number' => 1],
            ['number' => 2, 'date' => '2026-02-11'],
        ]);

        $this->assertSame(1, collect($outcomes)->where('ok', true)->count());
        $this->assertSame(1, BankStatement::count());
        $this->assertStringContainsString('MKD', collect($outcomes)->firstWhere('ok', false)->message);
    }

    public function test_the_same_file_twice_in_one_batch_is_imported_once(): void
    {
        $outcomes = $this->importBatch([['number' => 1], ['number' => 1]]);

        $this->assertSame(1, collect($outcomes)->where('ok', true)->count());
        $this->assertSame(1, collect($outcomes)->where('ok', false)->count());
        $this->assertSame(1, BankStatement::count());
    }

    public function test_an_unexpected_error_in_one_file_does_not_abort_the_batch(): void
    {
        $importer = new class extends Mt940Importer
        {
            public function import(Company $company, Mt940Statement $parsed, string $name, ?TemporaryUploadedFile $file = null): Mt940ImportOutcome
            {
                if ($parsed->number === 1) {
                    throw new \RuntimeException('boom');
                }

                return parent::import($company, $parsed, $name, $file);
            }
        };

        $outcomes = $importer->importMany($this->company, [
            ['name' => 'one.txt', 'text' => Mt940Fixture::statement(['number' => 1])],
            ['name' => 'two.txt', 'text' => Mt940Fixture::statement(['number' => 2, 'date' => '2026-02-11'])],
        ]);

        $this->assertCount(2, $outcomes);
        $this->assertSame(1, collect($outcomes)->where('ok', true)->count());
        $this->assertSame('one.txt', collect($outcomes)->firstWhere('ok', false)->file);
        $this->assertSame(1, BankStatement::count());
    }

    public function test_a_dashed_spelling_in_the_profile_is_the_same_account_for_duplicates(): void
    {
        CompanyBankAccount::create([
            'company_id' => $this->company->id, 'bank_name' => 'Банка', 'account_number' => '100-0000000000-01',
        ]);
        BankStatement::factory()->for($this->company)->create([
            'account' => '100-0000000000-01', 'number' => 5, 'statement_date' => '2026-02-02',
        ]);

        $outcome = $this->importOne(['number' => 5]);

        $this->assertFalse($outcome->ok);
        $this->assertStringContainsString('веќе постои', $outcome->message);
        $this->assertSame(1, BankStatement::count());
    }

    public function test_a_new_statement_keeps_the_spelling_the_company_already_uses(): void
    {
        CompanyBankAccount::create([
            'company_id' => $this->company->id, 'bank_name' => 'Банка', 'account_number' => '100-0000000000-01',
        ]);

        $outcome = $this->importOne(['number' => 1, 'lines' => [['dir' => 'in', 'amount' => '10.00']]]);

        $this->assertTrue($outcome->ok);
        $this->assertSame('100-0000000000-01', BankStatement::firstOrFail()->account);
        $this->assertFalse(collect($outcome->warnings)->contains(fn ($w) => str_contains($w, 'профилот')));
    }

    public function test_an_iban_in_the_profile_is_the_same_domestic_account(): void
    {
        CompanyBankAccount::create([
            'company_id' => $this->company->id, 'bank_name' => 'Банка', 'account_number' => 'MK07'.Mt940Fixture::ACCOUNT,
        ]);

        $outcome = $this->importOne(['number' => 1, 'lines' => [['dir' => 'in', 'amount' => '10.00']]]);

        $this->assertTrue($outcome->ok);
        $this->assertSame('MK07'.Mt940Fixture::ACCOUNT, BankStatement::firstOrFail()->account);
        $this->assertSame([], $outcome->warnings);
    }

    public function test_another_companys_dashed_account_is_still_that_companys_account(): void
    {
        $other = Company::factory()->create();
        CompanyBankAccount::create([
            'company_id' => $other->id, 'bank_name' => 'Туѓа', 'account_number' => '100-0000000000-01',
        ]);

        $outcome = $this->importOne(['number' => 1]);

        $this->assertFalse($outcome->ok);
        $this->assertSame(0, BankStatement::count());
    }

    public function test_a_file_saved_by_windows_is_imported(): void
    {
        $outcomes = app(Mt940Importer::class)->importMany($this->company, [[
            'name' => 'win.txt',
            'text' => "\xEF\xBB\xBF".Mt940Fixture::statement(['eol' => "\r\n", 'lines' => [['dir' => 'in', 'amount' => '10.00']]]),
        ]]);

        $this->assertTrue($outcomes[0]->ok, $outcomes[0]->message);
        $this->assertSame(1, BankStatement::firstOrFail()->lines()->count());
    }
}
