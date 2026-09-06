<?php

namespace Tests\Unit\Support;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyTabs;
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
        Role::findOrCreate('client');
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

    public function test_a_client_does_not_see_the_modules_tab(): void
    {
        $this->assertSame(
            ['Профил', 'Корисници'],
            $this->labels($this->userWithRole('client'), Company::factory()->create()),
        );
    }

    public function test_an_accountant_does_not_see_the_modules_tab(): void
    {
        $this->assertSame(
            ['Профил', 'Корисници'],
            $this->labels($this->userWithRole('accountant'), Company::factory()->create()),
        );
    }
}
