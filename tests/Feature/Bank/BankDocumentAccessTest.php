<?php

namespace Tests\Feature\Bank;

use App\Models\Company;
use App\Models\Form743;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Криењето во менито спречува кликање, не пишување адреса. Секој од двата
 * екрана е проверен во **двете** насоки: забрането за погрешниот тип клиент и
 * дозволено за вистинскиот. Втората половина е таа што спречува брана која
 * забранува сè.
 */
class BankDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function individual(): Company
    {
        return Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
    }

    public function test_an_individual_may_open_the_743_screen(): void
    {
        $this->actingAs($this->admin())
            ->get(route('form743.index', $this->individual()))
            ->assertOk();
    }

    public function test_a_legal_entity_may_not_open_the_743_screen(): void
    {
        $this->actingAs($this->admin())
            ->get(route('form743.index', Company::factory()->create()))
            ->assertForbidden();
    }

    public function test_a_legal_entity_may_not_reach_a_743_file(): void
    {
        $company = Company::factory()->create();
        $form = Form743::factory()->create(['company_id' => $company->id]);

        $this->actingAs($this->admin())
            ->get(route('form743.download', [$company, $form]))
            ->assertForbidden();
    }

    public function test_a_legal_entity_may_open_the_statements_screen(): void
    {
        $this->actingAs($this->admin())
            ->get(route('bank-statements.index', Company::factory()->create()))
            ->assertOk();
    }

    public function test_an_individual_may_not_open_the_statements_screen(): void
    {
        $this->actingAs($this->admin())
            ->get(route('bank-statements.index', $this->individual()))
            ->assertForbidden();
    }
}
