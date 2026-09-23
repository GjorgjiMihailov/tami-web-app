<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementIndex;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Support\BankStatementKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BankStatementIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function gapsIn(array $groups): array
    {
        return collect($groups)
            ->flatMap(fn (array $group) => $group['rows'])
            ->where('type', 'gap')
            ->values()
            ->all();
    }

    public function test_an_upload_stores_the_statement_with_its_file(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $company])
            ->set('bank', 'Комерцијална банка АД Скопје')
            ->set('account', '300000000000000')
            ->set('kind', BankStatementKind::FOREIGN->value)
            ->set('number', '12')
            ->set('statementDate', '2026-02-14')
            ->set('newFile', UploadedFile::fake()->create('izvod-12.pdf', 30))
            ->call('upload')
            ->assertHasNoErrors();

        $statement = BankStatement::where('company_id', $company->id)->firstOrFail();

        $this->assertSame(12, $statement->number);
        $this->assertSame(BankStatementKind::FOREIGN, $statement->kind);
        $this->assertSame('2026-02-14', $statement->statement_date->toDateString());

        $document = $statement->documents()->firstOrFail();
        $this->assertSame('izvod-12.pdf', $document->original_filename);
        Storage::disk('google')->assertExists($document->path);
    }

    /**
     * Ова е причината бројот воопшто да се внесува: фален извод значи
     * непрокнижен промет, што инаку се открива дури на крајот од годината.
     */
    public function test_an_internal_client_can_upload_a_statement(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        Livewire::actingAs($client)
            ->test(BankStatementIndex::class, ['company' => $company])
            ->set('bank', 'Стопанска банка')
            ->set('account', 'MK07300000000001234')
            ->set('kind', BankStatementKind::DENAR->value)
            ->set('number', '1')
            ->set('statementDate', now()->toDateString())
            ->set('newFile', UploadedFile::fake()->create('izvod-1.pdf', 20))
            ->call('upload')
            ->assertHasNoErrors();

        $this->assertSame(1, BankStatement::where('company_id', $company->id)->count());
    }

    public function test_an_internal_client_cannot_open_another_companys_statements(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $own->id]);
        $client->assignRole('internal_client');

        $this->actingAs($client)
            ->get(route('bank-statements.index', $other))
            ->assertForbidden();
    }

    public function test_a_missing_number_is_reported(): void
    {
        $company = Company::factory()->create();
        BankStatement::factory()->for($company)->create(['number' => 46, 'statement_date' => '2026-02-01']);
        BankStatement::factory()->for($company)->create(['number' => 48, 'statement_date' => '2026-02-05']);
        $this->actingAs($this->admin());

        $gaps = $this->gapsIn(
            Livewire::test(BankStatementIndex::class, ['company' => $company])->viewData('groups')
        );

        $this->assertCount(1, $gaps);
        $this->assertSame(47, $gaps[0]['from']);
        $this->assertSame(47, $gaps[0]['to']);
    }

    public function test_a_longer_break_is_reported_as_a_range(): void
    {
        $company = Company::factory()->create();
        BankStatement::factory()->for($company)->create(['number' => 3, 'statement_date' => '2026-01-10']);
        BankStatement::factory()->for($company)->create(['number' => 7, 'statement_date' => '2026-01-20']);
        $this->actingAs($this->admin());

        $gaps = $this->gapsIn(
            Livewire::test(BankStatementIndex::class, ['company' => $company])->viewData('groups')
        );

        $this->assertCount(1, $gaps);
        $this->assertSame(4, $gaps[0]['from']);
        $this->assertSame(6, $gaps[0]['to']);
    }

    /**
     * Половината што најмногу вреди: список без дупка не смее да пријавува
     * ништо, инаку црвениот ред станува шум што никој не го гледа.
     */
    public function test_a_complete_sequence_says_nothing(): void
    {
        $company = Company::factory()->create();
        foreach ([1, 2, 3] as $number) {
            BankStatement::factory()->for($company)->create([
                'number' => $number,
                'statement_date' => '2026-01-0'.$number,
            ]);
        }
        $this->actingAs($this->admin());

        $groups = Livewire::test(BankStatementIndex::class, ['company' => $company])->viewData('groups');

        $this->assertSame([], $this->gapsIn($groups));
        $this->assertCount(1, $groups);
        $this->assertCount(3, $groups[0]['rows']);
    }

    /**
     * Девизната и денарската сметка бројат одделно. Без ова, извод 1 на едната
     * и извод 40 на другата би пријавиле 38 фалени изводи.
     */
    public function test_each_account_counts_on_its_own(): void
    {
        $company = Company::factory()->create();
        BankStatement::factory()->for($company)->create(['account' => '300000000000000', 'number' => 1, 'statement_date' => '2026-01-05']);
        BankStatement::factory()->for($company)->foreign()->create(['number' => 40, 'statement_date' => '2026-01-06']);
        $this->actingAs($this->admin());

        $groups = Livewire::test(BankStatementIndex::class, ['company' => $company])->viewData('groups');

        $this->assertCount(2, $groups);
        $this->assertSame([], $this->gapsIn($groups));
    }

    /**
     * Броењето почнува одново секоја година, па извод 1 во 2026 по извод 52 во
     * 2025 не е прекин.
     */
    public function test_the_sequence_starts_over_each_year(): void
    {
        $company = Company::factory()->create();
        BankStatement::factory()->for($company)->create(['number' => 52, 'statement_date' => '2025-12-30']);
        BankStatement::factory()->for($company)->create(['number' => 1, 'statement_date' => '2026-01-05']);
        $this->actingAs($this->admin());

        $groups = Livewire::test(BankStatementIndex::class, ['company' => $company])->viewData('groups');

        $this->assertCount(2, $groups);
        $this->assertSame([], $this->gapsIn($groups));
        $this->assertSame(2026, $groups[0]['year']);
    }

    public function test_the_same_number_cannot_be_uploaded_twice(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        BankStatement::factory()->for($company)->create([
            'account' => '300000000000000',
            'number' => 5,
            'statement_date' => '2026-01-20',
        ]);
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $company])
            ->set('bank', 'Комерцијална банка АД Скопје')
            ->set('account', '300000000000000')
            ->set('kind', BankStatementKind::DENAR->value)
            ->set('number', '5')
            ->set('statementDate', '2026-01-21')
            ->set('newFile', UploadedFile::fake()->create('izvod-5.pdf', 10))
            ->call('upload')
            ->assertHasErrors('number');

        $this->assertSame(1, BankStatement::where('company_id', $company->id)->count());
    }

    public function test_an_upload_needs_every_field(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $company])
            ->set('bank', '')
            ->set('account', '')
            ->call('upload')
            ->assertHasErrors(['bank', 'account', 'number', 'statementDate', 'newFile']);

        $this->assertSame(0, BankStatement::count());
    }

    private function statementDocument(Company $company): Document
    {
        Storage::fake('google');
        $statement = BankStatement::factory()->create(['company_id' => $company->id]);
        $document = Document::factory()->for($statement, 'documentable')->create([
            'company_id' => $company->id,
            'path' => 'documents/test/izvod.pdf',
        ]);
        Storage::disk('google')->put($document->path, 'fake-pdf-content');

        return $document;
    }

    private function clientOf(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        return $user;
    }

    public function test_an_admin_an_assigned_accountant_and_the_own_client_can_download_a_statement_file(): void
    {
        $company = Company::factory()->create();
        $document = $this->statementDocument($company);

        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        foreach ([$this->admin(), $accountant, $this->clientOf($company)] as $user) {
            $this->actingAs($user)
                ->get(route('documents.download', [$company, $document]))
                ->assertOk();
        }
    }

    public function test_another_companys_client_and_an_unassigned_accountant_cannot_download_a_statement_file(): void
    {
        $company = Company::factory()->create();
        $document = $this->statementDocument($company);

        $unassigned = User::factory()->create();
        $unassigned->assignRole('accountant');

        foreach ([$this->clientOf(Company::factory()->create()), $unassigned] as $user) {
            $this->actingAs($user)
                ->get(route('documents.download', [$company, $document]))
                ->assertForbidden();
        }
    }
}
