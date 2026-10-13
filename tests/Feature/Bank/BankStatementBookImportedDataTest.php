<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\User;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BankStatementBookImportedDataTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->company = Company::factory()->create();
    }

    private function importedStatement(int $number, string $party, string $name): BankStatement
    {
        $statement = BankStatement::factory()->for($this->company)->create([
            'number' => $number, 'statement_date' => '2026-02-02',
        ]);
        BankStatementLine::factory()->for($statement)->create([
            'direction' => LineDirection::IN,
            'amount' => '17.00',
            'kind' => LineKind::UNCLEAR,
            'description' => 'Plakane',
            'reference_number' => '1111111111//22222222222',
            'counterparty_account' => $party,
            'counterparty_name' => $name,
        ]);

        return $statement;
    }

    private function screen(BankStatement $statement)
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $statement]);
    }

    public function test_saving_twice_keeps_what_came_with_the_file(): void
    {
        $statement = $this->importedStatement(1, '100000000000002', 'BANKA TEST AD');

        $this->screen($statement)
            ->set('lines.0.description', 'dopolneto')
            ->call('save')
            ->call('save');

        $line = $statement->fresh()->lines()->firstOrFail();
        $this->assertSame('100000000000002', $line->counterparty_account);
        $this->assertSame('BANKA TEST AD', $line->counterparty_name);
        $this->assertSame('1111111111//22222222222', $line->reference_number);
        $this->assertSame('dopolneto', $line->description);
    }

    public function test_a_line_added_by_hand_has_no_import_data_and_the_imported_one_keeps_its_own(): void
    {
        $statement = $this->importedStatement(1, '100000000000002', 'BANKA TEST AD');

        $this->screen($statement)
            ->call('addLine')
            ->set('lines.1.amount', '5.00')
            ->call('save');

        $lines = $statement->fresh()->lines()->get();
        $this->assertCount(2, $lines);
        $this->assertSame('100000000000002', $lines[0]->counterparty_account);
        $this->assertNull($lines[1]->counterparty_account);
        $this->assertNull($lines[1]->reference_number);
    }

    public function test_a_changed_id_cannot_borrow_data_from_another_statement(): void
    {
        $own = $this->importedStatement(1, '100000000000002', 'OWN');
        $other = $this->importedStatement(2, '100000000000003', 'OTHER');
        $otherLineId = $other->lines()->firstOrFail()->id;

        $this->screen($own)
            ->set('lines.0.id', $otherLineId)
            ->call('save')
            ->assertHasErrors('post');

        $this->assertSame('100000000000002', $own->fresh()->lines()->firstOrFail()->counterparty_account);
        $this->assertSame('100000000000003', $other->fresh()->lines()->firstOrFail()->counterparty_account);
    }

    public function test_the_other_side_and_the_reference_are_shown_on_the_screen(): void
    {
        $statement = $this->importedStatement(1, '100000000000002', 'BANKA TEST AD');

        $this->screen($statement)
            ->assertSee('BANKA TEST AD')
            ->assertSee('100000000000002')
            ->assertSee('1111111111//22222222222');
    }
}
