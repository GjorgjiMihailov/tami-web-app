<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    public function test_a_client_sees_the_payroll_group_with_only_employees_in_it(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $groups = collect(Menu::for($client, $company));
        $payroll = $groups->firstWhere('key', 'payroll');

        $this->assertNotNull($payroll, 'A client should now see the ПЛАТИ И ЧР group.');
        $this->assertSame(['Вработени'], array_column($payroll['items'], 'label'));
    }

    public function test_an_admin_still_sees_the_two_unbuilt_entries(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payroll = collect(Menu::for($admin, $company))->firstWhere('key', 'payroll');

        $this->assertSame(
            ['Вработени', 'Плата (МПИН)', 'е-ПДД'],
            array_column($payroll['items'], 'label')
        );
    }

    public function test_the_employees_menu_item_points_at_a_real_route(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payroll = collect(Menu::for($admin, $company))->firstWhere('key', 'payroll');
        $employees = collect($payroll['items'])->firstWhere('label', 'Вработени');

        $this->assertFalse($employees['soon'], 'Вработени is built — it must no longer be a "наскоро" item.');
        $this->assertSame(route('employees.index', $company), $employees['url']);
    }

    public function test_every_role_may_open_the_employees_screen(): void
    {
        $company = Company::factory()->create();

        foreach (['admin', 'accountant'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            if ($role === 'accountant') {
                $company->accountants()->attach($user);
            }

            $this->actingAs($user)->get(route('employees.index', $company))->assertOk();
        }

        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)->get(route('employees.index', $company))->assertOk();
    }

    public function test_a_client_may_not_open_another_companys_employees(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();

        $client = User::factory()->create(['company_id' => $own->id]);
        $client->assignRole('client');

        $this->actingAs($client)->get(route('employees.index', $other))->assertForbidden();
    }
}
