<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\BankLineRule;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Services\Bank\BankStatementPoster;
use App\Services\Bank\Mt940Importer;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use App\Support\Bank\MatchConfidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class BankLineRuleTest extends TestCase
{
    use RefreshDatabase;

    private const BANK = '100000000000002';

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

    private function konto(string $code = '7400'): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private int $nextNumber = 1;

    /** @param  array<string, mixed>  $line */
    private function book(array $line): void
    {
        $number = $this->nextNumber++;
        $statement = BankStatement::factory()->for($this->company)->create([
            'number' => $number, 'statement_date' => '2026-03-05',
            'opening_balance' => (($number - 1) * 10).'.00', 'closing_balance' => ($number * 10).'.00',
        ]);

        BankStatementLine::factory()->for($statement)->create(array_merge([
            'line_date' => '2026-03-05', 'direction' => LineDirection::IN, 'amount' => '10.00',
            'kind' => LineKind::ACCOUNT, 'account_id' => $this->konto()->id,
            'counterparty_account' => self::BANK, 'counterparty_name' => 'TEST BANKA AD',
            'description' => 'Mesecna provizija za mesec 02/2026',
        ], $line));

        app(BankStatementPoster::class)->post($statement->fresh(), $this->admin->id);
    }

    private int $importNumber = 1;

    private function importLine(string $description, string $dir = 'in'): BankStatementLine
    {
        $outcome = app(Mt940Importer::class)->importMany($this->company, [[
            'name' => 'a.txt',
            'text' => Mt940Fixture::statement(['number' => $this->importNumber++, 'lines' => [['dir' => $dir, 'amount' => '10.00', 'party' => self::BANK, 'desc' => $description]]]),
        ]])[0];

        return BankStatement::findOrFail($outcome->statementId)->lines()->firstOrFail();
    }

    // --- памтење ---

    public function test_booking_remembers_a_rule_per_account_direction_and_pattern(): void
    {
        $this->book([]);

        $rule = BankLineRule::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame(self::BANK, $rule->account_key);
        $this->assertSame('in', $rule->direction);
        $this->assertSame('mesecna provizija za mesec #', $rule->pattern);
        $this->assertSame('Mesecna provizija za mesec 02/2026', $rule->example_text);
        $this->assertSame($this->konto()->id, $rule->account_id);
        $this->assertSame(1, $rule->times_used);
    }

    public function test_the_same_kind_of_text_updates_one_rule_and_the_latest_account_wins(): void
    {
        $this->book([]);
        $other = $this->konto('7410');

        $this->book(['description' => 'Mesecna provizija za mesec 03/2026', 'account_id' => $other->id]);

        $this->assertSame(1, BankLineRule::count());
        $rule = BankLineRule::firstOrFail();
        $this->assertSame($other->id, $rule->account_id);
        $this->assertSame(2, $rule->times_used);
    }

    public function test_a_text_without_meaning_creates_no_rule(): void
    {
        $this->book(['description' => '000']);

        $this->assertSame(0, BankLineRule::count());
    }

    public function test_the_rule_is_kept_per_company(): void
    {
        $this->book([]);

        $this->assertSame(0, BankLineRule::where('company_id', '!=', $this->company->id)->count());
    }

    // --- препознавање ---

    public function test_the_same_kind_of_text_next_month_arrives_with_the_account(): void
    {
        $this->book([]);

        $line = $this->importLine('Mesecna provizija za mesec 04/2026');

        $this->assertSame(LineKind::ACCOUNT, $line->kind);
        $this->assertSame($this->konto()->id, $line->account_id);
        $this->assertSame(MatchConfidence::CHECK, $line->match_confidence);
        $this->assertSame('memory', $line->match_source);
        $this->assertStringContainsString('7400', (string) $line->match_reason);
        $this->assertNull($line->reviewed_at);
    }

    public function test_one_account_booked_to_different_accounts_by_text_is_told_apart(): void
    {
        $fee = $this->konto('7400');
        $other = $this->konto('7410');
        $this->book(['description' => 'Mesecna provizija za mesec 02/2026', 'account_id' => $fee->id]);
        $this->book(['description' => 'Otkup EUR 2.950.00', 'account_id' => $other->id]);

        $this->assertSame($fee->id, $this->importLine('Mesecna provizija za mesec 09/2026')->account_id);
        $this->assertSame($other->id, $this->importLine('Otkup EUR 100.00')->account_id);
    }

    public function test_an_unknown_text_on_an_account_with_different_accounts_gets_no_account(): void
    {
        $this->book(['description' => 'Mesecna provizija za mesec 02/2026', 'account_id' => $this->konto('7400')->id]);
        $this->book(['description' => 'Otkup EUR 2.950.00', 'account_id' => $this->konto('7410')->id]);

        $line = $this->importLine('Nesto sosem novo');

        $this->assertNull($line->account_id);
        $this->assertNull($line->match_source);
    }

    public function test_an_unknown_text_on_an_account_that_always_went_to_one_account_gets_that_account(): void
    {
        $this->book(['description' => 'Vrakane na sredstva po pozajmica']);

        $line = $this->importLine('Ostanati plakana');

        $this->assertSame($this->konto()->id, $line->account_id);
        $this->assertSame(MatchConfidence::CHECK, $line->match_confidence);
        $this->assertStringContainsString('секогаш', (string) $line->match_reason);
    }

    public function test_the_direction_is_part_of_the_rule(): void
    {
        $this->book([]);

        $line = $this->importLine('Mesecna provizija za mesec 04/2026', 'out');

        $this->assertNull($line->account_id);
    }

    public function test_a_text_that_mentions_an_invoice_never_gets_an_account(): void
    {
        $this->book(['description' => 'faktura 00004-26']);
        $this->book(['description' => 'Mesecna provizija za mesec 02/2026']);

        $line = $this->importLine('faktura 00007-26');

        $this->assertNull($line->account_id);
        $this->assertNotNull($line->match_reason);
    }

    public function test_a_rule_whose_account_is_no_longer_usable_gives_no_account(): void
    {
        $this->book([]);
        $this->konto()->update(['is_active' => false]);

        $this->assertNull($this->importLine('Mesecna provizija za mesec 04/2026')->account_id);
    }

    public function test_the_partner_of_the_rule_arrives_with_the_line(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        $this->book(['partner_id' => $partner->id]);

        $this->assertSame($partner->id, $this->importLine('Mesecna provizija za mesec 04/2026')->partner_id);
    }

    // --- миграција ---

    public function test_the_migration_fills_rules_from_booked_statements(): void
    {
        $this->book(['description' => 'Mesecna provizija za mesec 02/2026']);
        $this->book(['description' => 'Mesecna provizija za mesec 03/2026', 'account_id' => $this->konto('7410')->id]);
        $expected = BankLineRule::firstOrFail()->only(['pattern', 'account_id', 'times_used']);

        $migration = require base_path('database/migrations/2026_10_21_100000_create_bank_line_rules_table.php');
        Schema::dropIfExists('bank_line_rules');
        $migration->up();

        $rule = BankLineRule::firstOrFail();
        $this->assertSame(1, BankLineRule::count());
        $this->assertSame($expected['pattern'], $rule->pattern);
        $this->assertSame($expected['account_id'], $rule->account_id);
        $this->assertSame($expected['times_used'], $rule->times_used);
    }
}
