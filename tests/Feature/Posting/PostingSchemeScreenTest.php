<?php

namespace Tests\Feature\Posting;

use App\Livewire\Accounting\PostingSchemeIndex;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PostingSchemeScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function accountantOf(Company $company): User
    {
        $user = User::factory()->create();
        $user->assignRole('accountant');
        $user->assignedCompanies()->attach($company);

        return $user;
    }

    public function test_the_index_lists_all_four_schemes_and_creates_the_missing_ones(): void
    {
        $company = Company::factory()->create();
        $this->assertSame(0, PostingScheme::where('company_id', $company->id)->count());

        Livewire::actingAs($this->accountantOf($company))
            ->test(PostingSchemeIndex::class, ['company' => $company])
            ->assertSee('Излезна фактура')
            ->assertSee('Уплата од купувач')
            ->assertSee('Влезна фактура')
            ->assertSee('Исплата кон добавувач');

        $this->assertSame(4, PostingScheme::where('company_id', $company->id)->count());
    }

    public function test_the_route_is_open_to_an_accountant_of_the_company_and_closed_to_others(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');
        $stranger = $this->accountantOf(Company::factory()->create());

        $this->actingAs($this->accountantOf($company))->get(route('accounting.posting-schemes.index', $company))->assertOk();
        $this->actingAs($client)->get(route('accounting.posting-schemes.index', $company))->assertForbidden();
        $this->actingAs($stranger)->get(route('accounting.posting-schemes.index', $company))->assertForbidden();
    }

    public function test_the_menu_links_the_screen_for_an_accountant(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->accountantOf($company))->get(route('accounting.accounts.index', $company))
            ->assertSee('Шеми за книжење');
    }
}
