<?php

namespace Tests\Unit\Support;

use App\Models\Company;
use App\Models\User;
use App\Support\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function userWithRole(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create($company && $role === 'client' ? ['company_id' => $company->id] : []);
        $user->assignRole($role);

        return $user;
    }

    /** @return list<string> */
    private function groupLabels(array $menu): array
    {
        return array_column($menu, 'label');
    }

    /** @return list<string> */
    private function itemLabels(array $menu, string $groupKey): array
    {
        foreach ($menu as $group) {
            if ($group['key'] === $groupKey) {
                return array_column($group['items'], 'label');
            }
        }

        return [];
    }

    public function test_an_admin_sees_every_group(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(
            ['ФИНАНСИИ', 'ПРОДАЖБА', 'ЗАЛИХА', 'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ', 'ПОСТАВКИ'],
            $this->groupLabels(Menu::for($this->userWithRole('admin'), $company))
        );
    }

    public function test_an_admin_sees_the_full_finance_and_settings_items(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('admin'), $company);

        $this->assertSame(['Главна книга', 'Извештаи и обрасци', 'Изводи'], $this->itemLabels($menu, 'finance'));
        $this->assertSame(['Компанија', 'Контен план', 'е-Фактура барања'], $this->itemLabels($menu, 'settings'));
    }

    public function test_an_accountant_sees_finance_but_no_efaktura_requests(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('accountant'), $company);

        $this->assertContains('ФИНАНСИИ', $this->groupLabels($menu));
        $this->assertSame(['Компанија', 'Контен план'], $this->itemLabels($menu, 'settings'));
    }

    public function test_a_client_sees_no_finance_group_at_all(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('client', $company), $company);

        $this->assertNotContains('ФИНАНСИИ', $this->groupLabels($menu));
    }

    public function test_a_client_sees_only_the_company_item_under_settings(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('client', $company), $company);

        $this->assertSame(['Компанија'], $this->itemLabels($menu, 'settings'));
    }

    public function test_a_client_never_sees_a_naskoro_item(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('client', $company), $company);

        foreach ($menu as $group) {
            foreach ($group['items'] as $item) {
                $this->assertFalse($item['soon'], "Client must not see the наскоро item {$item['label']}.");
            }
        }
    }

    // Follows from the two rules above rather than being hardcoded: every item in
    // ПЛАТИ И ЧР is unbuilt today, clients do not see unbuilt items, and a group
    // with no visible items is dropped. The day that module ships, the group
    // reappears for clients with no change to Menu's role rules.
    public function test_a_group_whose_items_are_all_hidden_disappears(): void
    {
        $company = Company::factory()->create();

        $this->assertNotContains(
            'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ',
            $this->groupLabels(Menu::for($this->userWithRole('client', $company), $company))
        );
        $this->assertContains(
            'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ',
            $this->groupLabels(Menu::for($this->userWithRole('admin'), $company))
        );
    }

    public function test_a_client_still_gets_the_full_sales_and_stock_groups(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('client', $company), $company);

        $this->assertSame(['Излезни фактури', 'Влезни фактури', 'Кооперанти'], $this->itemLabels($menu, 'sales'));
        $this->assertSame(
            ['Магацини', 'Артикли', 'Состојба', 'Прием', 'Излез', 'Пренос'],
            $this->itemLabels($menu, 'stock')
        );
    }

    public function test_every_item_carries_a_resolved_url_and_a_route_pattern(): void
    {
        $company = Company::factory()->create();

        foreach (Menu::for($this->userWithRole('admin'), $company) as $group) {
            foreach ($group['items'] as $item) {
                $this->assertStringStartsWith('http', $item['url'], "{$item['label']} has no resolved URL.");
                $this->assertNotSame('', $item['pattern'], "{$item['label']} has no route pattern.");
            }
        }
    }

    public function test_korekcija_is_not_in_the_menu(): void
    {
        $company = Company::factory()->create();

        foreach (Menu::for($this->userWithRole('admin'), $company) as $group) {
            $this->assertNotContains('Корекција', array_column($group['items'], 'label'));
        }
    }
}
