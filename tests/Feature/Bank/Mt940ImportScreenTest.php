<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementIndex;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\Mt940Fixture;
use Tests\TestCase;

class Mt940ImportScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
        Storage::fake('google');
        $this->company = Company::factory()->create();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /** @param  array<string, mixed>  $options */
    private function file(string $name, array $options = []): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, Mt940Fixture::statement($options));
    }

    public function test_two_files_are_imported_at_once_and_each_is_kept_as_a_document(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [
                $this->file('a.txt', ['number' => 1, 'lines' => [['dir' => 'in', 'amount' => '100.00']]]),
                $this->file('b.txt', ['number' => 2, 'date' => '2026-02-11', 'opening' => '100.00', 'lines' => [['dir' => 'out', 'amount' => '30.00']]]),
            ])
            ->call('importMt940Files')
            ->assertHasNoErrors()
            ->assertSee('Извод бр. 1')
            ->assertSee('Извод бр. 2');

        $this->assertSame(2, BankStatement::where('company_id', $this->company->id)->count());

        foreach (BankStatement::all() as $statement) {
            $document = $statement->documents()->firstOrFail();
            Storage::disk('google')->assertExists($document->path);
        }
    }

    public function test_a_refused_file_shows_its_reason_and_creates_nothing(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [$this->file('eur.txt', ['currency' => 'EUR'])])
            ->call('importMt940Files')
            ->assertSee('eur.txt')
            ->assertSee('MKD');

        $this->assertSame(0, BankStatement::count());
    }

    public function test_a_warning_is_shown_next_to_the_imported_statement(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [$this->file('a.txt', ['lines' => [['dir' => 'in', 'amount' => '10.00']]])])
            ->call('importMt940Files')
            ->assertSee('не е во профилот');
    }

    public function test_the_same_file_twice_in_one_upload_is_imported_once(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [$this->file('a.txt', ['number' => 1]), $this->file('a-copy.txt', ['number' => 1])])
            ->call('importMt940Files')
            ->assertSee('веќе постои');

        $this->assertSame(1, BankStatement::count());
    }

    public function test_a_wrong_file_type_is_a_validation_error(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [UploadedFile::fake()->create('izvod.pdf', 10)])
            ->call('importMt940Files')
            ->assertHasErrors('mt940Files.0');

        $this->assertSame(0, BankStatement::count());
    }

    public function test_no_file_is_a_validation_error(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])
            ->call('importMt940Files')
            ->assertHasErrors('mt940Files');
    }

    public function test_an_internal_client_cannot_import_and_does_not_see_the_button(): void
    {
        $client = User::factory()->create(['company_id' => $this->company->id]);
        $client->assignRole('internal_client');

        $html = Livewire::actingAs($client)->test(BankStatementIndex::class, ['company' => $this->company])->html();
        $this->assertStringNotContainsString('Увези MT940', $html);

        Livewire::actingAs($client)->test(BankStatementIndex::class, ['company' => $this->company])
            ->set('mt940Files', [$this->file('a.txt')])
            ->call('importMt940Files')
            ->assertForbidden();

        $this->assertSame(0, BankStatement::count());
    }

    public function test_an_admin_sees_the_button(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(BankStatementIndex::class, ['company' => $this->company])->assertSee('Увези MT940');
    }
}
