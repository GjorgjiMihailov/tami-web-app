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
            ['ФИНАНСИИ', 'ПРОДАЖБА', 'ТРОШОЦИ', 'ЗАЛИХА', 'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ', 'ПОСТАВКИ'],
            $this->groupLabels(Menu::for($this->userWithRole('admin'), $company))
        );
    }

    public function test_an_admin_sees_the_full_finance_and_settings_items(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('admin'), $company);

        $this->assertSame(['Главна книга', 'Извештаи и обрасци', 'Банкарски документи'], $this->itemLabels($menu, 'finance'));
        $this->assertSame(['Компанија', 'Контен план', 'е-Фактура барања', 'Параметри за плата'], $this->itemLabels($menu, 'settings'));
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

    // Вработени is now built, so the ПЛАТИ И ЧР group survives for a client —
    // carrying only that one item — while the two still-unbuilt entries stay
    // admin/accountant-only. This is the same "drops when empty, survives
    // once something is visible" rule as before; it just no longer applies
    // to this group, since it is no longer empty for a client.
    public function test_a_client_sees_the_payroll_group_with_only_the_built_item(): void
    {
        $company = Company::factory()->create();

        $this->assertSame(
            ['Вработени'],
            $this->itemLabels(Menu::for($this->userWithRole('client', $company), $company), 'payroll')
        );
        $this->assertSame(
            ['Вработени', 'Плата (МПИН)', 'е-ПДД'],
            $this->itemLabels(Menu::for($this->userWithRole('admin'), $company), 'payroll')
        );
    }

    // The label-only assertions above pass identically whether the entry is
    // a real route or still a "coming soon" stub with the same label and
    // visibility rule, so they cannot catch a revert of that change. This
    // inspects the item itself: a real route carries its own route pattern,
    // while Menu::soon() always sets 'pattern' => 'coming-soon' and
    // 'soon' => true.
    public function test_the_payroll_run_menu_item_is_a_real_route_not_a_soon_stub(): void
    {
        $company = Company::factory()->create();
        $payroll = collect(Menu::for($this->userWithRole('admin'), $company))->firstWhere('key', 'payroll');
        $item = collect($payroll['items'])->firstWhere('label', 'Плата (МПИН)');

        $this->assertSame('payroll-runs.*', $item['pattern']);
        $this->assertFalse($item['soon'], 'Плата (МПИН) must not be a "наскоро" stub.');
    }

    public function test_a_client_still_gets_the_full_sales_costs_and_stock_groups(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('client', $company), $company);

        // Влезни фактури moved out of ПРОДАЖБА into its own ТРОШОЦИ group.
        $this->assertSame(['Излезни фактури', 'Кооперанти'], $this->itemLabels($menu, 'sales'));
        // Други трошоци се отвори за клиент во фаза В — фискалните сметки ги
        // качува тој, како и влезните фактури.
        $this->assertSame(['Влезни фактури', 'Други трошоци'], $this->itemLabels($menu, 'costs'));
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
