<?php

namespace Tests\Feature;

use App\Livewire\ClientIndex;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Фирма што ја работи само сметководител (нема своја сметка на порталот) не се
 * гледаше во листата „Клиенти“, па не можеше ни да се избрише. Сега има свој
 * дел, со податоците што ги има — за админ да одлучи што е дупликат.
 */
class ClientsPageWorkedCompaniesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'internal_client'] as $role) {
            Role::findOrCreate($role);
        }

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    private function accountant(): User
    {
        $user = User::factory()->create(['name' => 'Сметководител Тест']);
        $user->assignRole('accountant');

        return $user;
    }

    public function test_a_company_without_its_own_account_is_listed_with_its_accountant(): void
    {
        $company = Company::factory()->create(['name' => 'БЕЗ СМЕТКА ДООЕЛ']);
        $company->accountants()->attach($this->accountant());

        Livewire::test(ClientIndex::class)
            ->assertSee('Фирми што ги води сметководител')
            ->assertSee('БЕЗ СМЕТКА ДООЕЛ')
            ->assertSee('Сметководител Тест');
    }

    public function test_a_company_with_its_own_account_is_not_listed_twice(): void
    {
        $company = Company::factory()->create(['name' => 'СО СМЕТКА ДООЕЛ']);
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $html = Livewire::test(ClientIndex::class)->html();

        $this->assertSame(1, substr_count($html, 'СО СМЕТКА ДООЕЛ'));
        $this->assertStringNotContainsString('Фирми што ги води сметководител', $html);
    }

    public function test_the_data_of_each_company_is_shown_so_a_duplicate_can_be_told_apart(): void
    {
        $withData = Company::factory()->create(['name' => 'СО ПОДАТОЦИ ДООЕЛ']);
        SalesInvoice::factory()->for($withData)->count(2)->create();
        BankStatement::factory()->for($withData)->create();
        Company::factory()->create(['name' => 'ПРАЗНА ДООЕЛ']);

        Livewire::test(ClientIndex::class)
            ->assertSee('2 излезни фактури')
            ->assertSee('1 извод')
            ->assertSee('Нема внесени податоци');
    }

    public function test_such_a_company_can_be_deleted_by_the_admin(): void
    {
        $company = Company::factory()->create(['name' => 'ЗА БРИШЕЊЕ ДООЕЛ']);

        Livewire::test(ClientIndex::class)
            ->call('requestDelete', 'company', $company->id)
            ->set('deleteConfirmation', 'ЗА БРИШЕЊЕ ДООЕЛ')
            ->call('confirmDelete');

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    public function test_two_companies_with_the_same_name_are_flagged_as_a_possible_duplicate(): void
    {
        Company::factory()->create(['name' => 'ИСТА ДООЕЛ']);
        Company::factory()->create(['name' => 'иста дооел ']);
        Company::factory()->create(['name' => 'РАЗЛИЧНА ДООЕЛ']);

        $html = Livewire::test(ClientIndex::class)->html();

        $this->assertSame(2, substr_count($html, 'Можен дупликат'));
    }
}
