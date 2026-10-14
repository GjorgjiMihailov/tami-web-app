<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementIndex;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Document;
use App\Models\User;
use App\Services\Bank\Mt940Importer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

/**
 * Погрешно увезен извод кај друга фирма ја заклучува сметката за вистинската:
 * пораката мора да каже каде е, а изводот во нацрт мора да може да се избрише.
 */
class Mt940ForeignAccountMessageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
        $this->company = Company::factory()->create(['name' => 'Вистинска ДООЕЛ']);
        $this->other = Company::factory()->create(['name' => 'Погрешна ДООЕЛ']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        $this->actingAs($user);

        return $user;
    }

    private function importInto(Company $company): string
    {
        $outcome = app(Mt940Importer::class)->importMany($company, [[
            'name' => 'a.txt',
            'text' => Mt940Fixture::statement(['number' => 5, 'date' => '2026-01-08']),
        ]])[0];

        return $outcome->message;
    }

    public function test_the_message_names_the_company_and_the_statement_that_holds_the_account(): void
    {
        $this->admin();
        BankStatement::factory()->for($this->other)->create([
            'account' => Mt940Fixture::ACCOUNT, 'number' => 3, 'statement_date' => '2026-01-05',
        ]);

        $message = $this->importInto($this->company);

        $this->assertStringContainsString('Погрешна ДООЕЛ', $message);
        $this->assertStringContainsString('извод бр. 3', $message);
        $this->assertStringContainsString('друга фирма', $message);
    }

    public function test_the_message_says_when_the_account_is_in_the_profile(): void
    {
        $this->admin();
        CompanyBankAccount::create([
            'company_id' => $this->other->id, 'bank_name' => 'Банка', 'account_number' => Mt940Fixture::ACCOUNT,
        ]);

        $message = $this->importInto($this->company);

        $this->assertStringContainsString('Погрешна ДООЕЛ', $message);
        $this->assertStringContainsString('во профилот', $message);
    }

    public function test_an_accountant_not_assigned_to_that_company_does_not_learn_its_name(): void
    {
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $this->company->accountants()->attach($accountant);
        $this->actingAs($accountant);

        BankStatement::factory()->for($this->other)->create(['account' => Mt940Fixture::ACCOUNT, 'number' => 3]);

        $message = $this->importInto($this->company);

        $this->assertStringNotContainsString('Погрешна ДООЕЛ', $message);
        $this->assertStringContainsString('не ви е доделена', $message);
    }

    public function test_a_draft_statement_can_be_deleted_and_then_the_account_is_free(): void
    {
        $this->admin();
        $statement = BankStatement::factory()->for($this->other)->create(['account' => Mt940Fixture::ACCOUNT, 'number' => 3]);
        BankStatementLine::factory()->for($statement)->create();
        $document = Document::factory()->for($statement, 'documentable')->create(['company_id' => $this->other->id]);

        Livewire::test(BankStatementIndex::class, ['company' => $this->other])
            ->call('deleteStatement', $statement->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('bank_statements', ['id' => $statement->id]);
        $this->assertSame(0, BankStatementLine::where('bank_statement_id', $statement->id)->count());
        $this->assertSoftDeleted('documents', ['id' => $document->id]);

        $outcome = app(Mt940Importer::class)->importMany($this->company, [[
            'name' => 'a.txt', 'text' => Mt940Fixture::statement(['number' => 5, 'date' => '2026-01-08']),
        ]])[0];
        $this->assertTrue($outcome->ok, $outcome->message);
    }

    public function test_a_booked_statement_cannot_be_deleted(): void
    {
        $this->admin();
        $statement = BankStatement::factory()->for($this->other)->create(['status' => BankStatement::STATUS_BOOKED]);

        Livewire::test(BankStatementIndex::class, ['company' => $this->other])
            ->call('deleteStatement', $statement->id)
            ->assertHasErrors('delete');

        $this->assertDatabaseHas('bank_statements', ['id' => $statement->id]);
    }

    public function test_another_companys_statement_is_not_found(): void
    {
        $this->admin();
        $statement = BankStatement::factory()->for($this->other)->create();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->call('deleteStatement', $statement->id);
    }

    public function test_an_internal_client_cannot_delete_a_statement(): void
    {
        $client = User::factory()->create(['company_id' => $this->company->id]);
        $client->assignRole('internal_client');
        $statement = BankStatement::factory()->for($this->company)->create();

        Livewire::actingAs($client)->test(BankStatementIndex::class, ['company' => $this->company])
            ->call('deleteStatement', $statement->id)
            ->assertForbidden();

        $this->assertDatabaseHas('bank_statements', ['id' => $statement->id]);
    }
}
