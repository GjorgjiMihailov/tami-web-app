<?php

namespace Tests\Unit\Support;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyTabs;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function labels(User $user, Company $company): array
    {
        return array_column(CompanyTabs::for($user, $company), 'label');
    }

    public function test_an_admin_sees_all_three_tabs(): void
    {
        $this->assertSame(
            ['Профил', 'Модули', 'Корисници'],
            $this->labels($this->userWithRole('admin'), Company::factory()->create()),
        );
    }

    public function test_an_admin_does_not_see_the_modules_tab_for_an_individual_company(): void
    {
        $this->assertSame(
            ['Профил', 'Корисници'],
            $this->labels($this->userWithRole('admin'), Company::factory()->create(['type' => CompanyType::INDIVIDUAL])),
        );
    }

    /**
     * 'Модули' сега е 'roles' => null, исто како 'Профил' и 'Корисници' —
     * CompanyTabs не е вистинската брана (CompanyPolicy::update е), затоа
     * листата на картички повеќе не го крие линкот за клиент. Клиентот
     * сепак не може да ја отвори (CompanyModules::mount() бара admin или
     * доделен сметководител), исто како што ниту порано не постоеше по-строга
     * заштита за 'Профил'/'Корисници' тука — само на екранот.
     */
    public function test_a_client_sees_all_three_tab_labels_but_cannot_open_modules(): void
    {
        $this->assertSame(
            ['Профил', 'Модули', 'Корисници'],
            $this->labels($this->userWithRole('internal_client'), Company::factory()->create()),
        );
    }

    public function test_an_accountant_assigned_to_the_company_sees_the_modules_tab(): void
    {
        // CompanyPolicy::update ги пушта таквите сметководители на екранот
        // CompanyModules — 'Модули' мора да им е видлива, исто како 'Профил'
        // и 'Корисници', инаку копчето постои, но никаде не води до него.
        $company = Company::factory()->create();
        $accountant = $this->userWithRole('accountant');
        $company->accountants()->attach($accountant);

        $this->assertSame(
            ['Профил', 'Модули', 'Корисници'],
            $this->labels($accountant, $company),
        );
    }
}
