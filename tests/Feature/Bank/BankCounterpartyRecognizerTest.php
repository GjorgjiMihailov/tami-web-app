<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\BankCounterparty;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Services\Bank\BankStatementPoster;
use App\Services\Bank\Mt940Importer;
use App\Support\Bank\LineKind;
use App\Support\Bank\MatchConfidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class BankCounterpartyRecognizerTest extends TestCase
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

    private function konto(string $code = '7400', ?Company $company = null): Account
    {
        return Account::where('company_id', ($company ?? $this->company)->id)->where('code', $code)->firstOrFail();
    }

    /** @param  array<string, mixed>  $options */
    private function import(array $options, ?Company $company = null): BankStatement
    {
        $outcome = app(Mt940Importer::class)->importMany($company ?? $this->company, [[
            'name' => 'a.txt', 'text' => Mt940Fixture::statement($options),
        ]])[0];

        return BankStatement::findOrFail($outcome->statementId);
    }

    public function test_a_remembered_account_arrives_prefilled_as_check(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        BankCounterparty::create([
            'direction' => 'in', 'company_id' => $this->company->id, 'account_key' => '100000000000002',
            'account_id' => $this->konto()->id, 'partner_id' => $partner->id,
        ]);

        $statement = $this->import(['lines' => [['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002']]]);

        $line = $statement->lines()->firstOrFail();
        $this->assertSame(LineKind::ACCOUNT, $line->kind);
        $this->assertSame($this->konto()->id, $line->account_id);
        $this->assertSame($partner->id, $line->partner_id);
        $this->assertSame(MatchConfidence::CHECK, $line->match_confidence);
        $this->assertStringContainsString('7400', (string) $line->match_reason);
        $this->assertNull($line->reviewed_at);
    }

    public function test_a_dashed_spelling_in_the_file_is_recognised(): void
    {
        BankCounterparty::create([
            'direction' => 'in', 'company_id' => $this->company->id, 'account_key' => '100000000000002', 'account_id' => $this->konto()->id,
        ]);

        $statement = $this->import(['lines' => [['dir' => 'in', 'amount' => '10.00', 'party' => 'MK07100000000000002']]]);

        $this->assertSame(MatchConfidence::CHECK, $statement->lines()->firstOrFail()->match_confidence);
    }

    public function test_another_companys_memory_is_not_used(): void
    {
        // Записот на другата фирма покажува кон КОНТО И ПАРТНЕР на оваа фирма:
        // само филтерот по фирма на самиот запис може да го запре.
        $partner = Partner::factory()->for($this->company)->create();
        $other = Company::factory()->create();
        BankCounterparty::create([
            'direction' => 'in', 'company_id' => $other->id, 'account_key' => '100000000000002',
            'account_id' => $this->konto()->id, 'partner_id' => $partner->id,
        ]);

        $line = $this->import(['lines' => [['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002']]])->lines()->firstOrFail();

        $this->assertSame(LineKind::UNCLEAR, $line->kind);
        $this->assertNull($line->account_id);
        $this->assertNull($line->partner_id);
        $this->assertSame(MatchConfidence::UNCLEAR, $line->match_confidence);
    }

    public function test_a_remembered_account_is_used_only_for_the_same_direction(): void
    {
        BankCounterparty::create([
            'direction' => 'out', 'company_id' => $this->company->id, 'account_key' => '100000000000002',
            'account_id' => $this->konto()->id,
        ]);

        $statement = $this->import(['lines' => [
            ['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002'],
            ['dir' => 'out', 'amount' => '3.00', 'party' => '100000000000002'],
        ]]);

        $lines = $statement->lines()->get();
        $this->assertNull($lines[0]->account_id);
        $this->assertSame(MatchConfidence::UNCLEAR, $lines[0]->match_confidence);
        $this->assertSame($this->konto()->id, $lines[1]->account_id);
    }

    public function test_an_inactive_account_in_the_memory_is_ignored(): void
    {
        $account = $this->konto();
        $account->update(['is_active' => false]);
        BankCounterparty::create([
            'direction' => 'in', 'company_id' => $this->company->id, 'account_key' => '100000000000002',
            'account_id' => $account->id,
        ]);

        $line = $this->import(['lines' => [['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002']]])->lines()->firstOrFail();

        $this->assertNull($line->account_id);
    }

    public function test_an_invoice_marker_in_the_text_prevents_the_prefill(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        BankCounterparty::create([
            'direction' => 'in', 'company_id' => $this->company->id, 'account_key' => '100000000000002',
            'account_id' => $this->konto()->id, 'partner_id' => $partner->id,
        ]);

        foreach (['faktura 00001-26', 'fra 00001-26', 'Plakane po faktura br. 5'] as $text) {
            $line = $this->import([
                'number' => random_int(1, 9999),
                'lines' => [['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002', 'desc' => $text]],
            ])->lines()->firstOrFail();

            $this->assertNull($line->account_id, $text);
            $this->assertSame(LineKind::UNCLEAR, $line->kind, $text);
            $this->assertSame(MatchConfidence::UNCLEAR, $line->match_confidence, $text);
            $this->assertStringContainsString('фактура', mb_strtolower((string) $line->match_reason), $text);
        }
    }

    public function test_a_prefilled_line_records_that_it_came_from_memory(): void
    {
        BankCounterparty::create([
            'direction' => 'in', 'company_id' => $this->company->id, 'account_key' => '100000000000002',
            'account_id' => $this->konto()->id,
        ]);

        $line = $this->import(['lines' => [['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002']]])->lines()->firstOrFail();

        $this->assertSame('memory', $line->match_source);
    }

    public function test_an_account_of_another_company_in_the_memory_is_ignored(): void
    {
        $other = Company::factory()->create();
        BankCounterparty::create([
            'direction' => 'in', 'company_id' => $this->company->id, 'account_key' => '100000000000002', 'account_id' => $this->konto('7400', $other)->id,
        ]);

        $line = $this->import(['lines' => [['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002']]])->lines()->firstOrFail();

        $this->assertNull($line->account_id);
        $this->assertSame(MatchConfidence::UNCLEAR, $line->match_confidence);
    }

    public function test_a_non_analytical_account_in_the_memory_is_ignored(): void
    {
        $heading = Account::where('company_id', $this->company->id)->where('is_analytical', false)->firstOrFail();
        BankCounterparty::create([
            'direction' => 'in', 'company_id' => $this->company->id, 'account_key' => '100000000000002', 'account_id' => $heading->id,
        ]);

        $line = $this->import(['lines' => [['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002']]])->lines()->firstOrFail();

        $this->assertNull($line->account_id);
    }

    public function test_only_a_remembered_partner_prefills_the_partner_and_stays_unclear(): void
    {
        $partner = Partner::factory()->for($this->company)->create();
        BankCounterparty::create([
            'direction' => 'in', 'company_id' => $this->company->id, 'account_key' => '100000000000002', 'partner_id' => $partner->id,
        ]);

        $line = $this->import(['lines' => [['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000002']]])->lines()->firstOrFail();

        $this->assertSame($partner->id, $line->partner_id);
        $this->assertSame(LineKind::UNCLEAR, $line->kind);
        $this->assertSame(MatchConfidence::UNCLEAR, $line->match_confidence);
        $this->assertStringContainsString('партнер', mb_strtolower((string) $line->match_reason));
    }

    public function test_an_unknown_account_is_unchanged(): void
    {
        $line = $this->import(['lines' => [['dir' => 'in', 'amount' => '10.00', 'party' => '100000000000009']]])->lines()->firstOrFail();

        $this->assertSame(LineKind::UNCLEAR, $line->kind);
        $this->assertSame('Другата страна не е препознаена.', $line->match_reason);
    }

    public function test_the_whole_cycle_book_one_statement_then_import_the_next(): void
    {
        $first = $this->import([
            'number' => 1, 'date' => '2026-03-05',
            'lines' => [['dir' => 'out', 'amount' => '10.00', 'party' => '100000000000007', 'name' => 'FEE BANK']],
        ]);

        $line = $first->lines()->firstOrFail();
        $line->update([
            'kind' => LineKind::ACCOUNT, 'account_id' => $this->konto()->id, 'reviewed_at' => now(),
        ]);
        app(BankStatementPoster::class)->post($first->fresh(), $this->admin->id);

        $second = $this->import([
            'number' => 2, 'date' => '2026-03-10', 'opening' => '-10.00',
            'lines' => [['dir' => 'out', 'amount' => '20.00', 'party' => '100000000000007', 'name' => 'FEE BANK']],
        ]);

        $next = $second->lines()->firstOrFail();
        $this->assertSame($this->konto()->id, $next->account_id);
        $this->assertSame(MatchConfidence::CHECK, $next->match_confidence);
    }
}
