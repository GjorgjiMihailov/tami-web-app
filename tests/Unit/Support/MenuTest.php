<?php

namespace Tests\Unit\Support;

use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use App\Support\Menu;
use App\Support\PortalApp;
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
        Role::findOrCreate('internal_client');
    }

    private function userWithRole(string $role, ?Company $company = null): User
    {
        $user = User::factory()->create($company && $role === 'internal_client' ? ['company_id' => $company->id] : []);
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
        $admin = $this->userWithRole('admin');

        $this->assertSame(['ФИНАНСИИ', 'ПОСТАВКИ'], $this->groupLabels(Menu::for($admin, $company, PortalApp::FINANSII)));
        $this->assertSame(['ПРОДАЖБА', 'ТРОШОЦИ', 'ЗАЛИХА', 'ПОСТАВКИ'], $this->groupLabels(Menu::for($admin, $company, PortalApp::PRODAZBA)));
        $this->assertSame(['ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ', 'ПОСТАВКИ'], $this->groupLabels(Menu::for($admin, $company, PortalApp::PLATA)));
        $this->assertSame(['ПОСТАВКИ'], $this->groupLabels(Menu::for($admin, $company, PortalApp::PORTAL)));
    }

    public function test_an_admin_sees_the_full_finance_and_settings_items(): void
    {
        $company = Company::factory()->create();
        $admin = $this->userWithRole('admin');

        $financeMenu = Menu::for($admin, $company, PortalApp::FINANSII);
        $this->assertSame(['Главна книга', 'Извештаи и обрасци', 'Банкарски документи'], $this->itemLabels($financeMenu, 'finance'));
        $this->assertSame(['Контен план'], $this->itemLabels($financeMenu, 'finance-settings'));

        $this->assertSame(['Компанија', 'е-Фактура барања'], $this->itemLabels(Menu::for($admin, $company, PortalApp::PORTAL), 'settings'));
        $this->assertSame(['Фактурирање'], $this->itemLabels(Menu::for($admin, $company, PortalApp::PRODAZBA), 'sales-settings'));
        $this->assertSame(['Параметри за плата'], $this->itemLabels(Menu::for($admin, $company, PortalApp::PLATA), 'payroll-settings'));
    }

    public function test_an_accountant_sees_finance_but_no_efaktura_requests(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->userWithRole('accountant');

        $this->assertContains('ФИНАНСИИ', $this->groupLabels(Menu::for($accountant, $company, PortalApp::FINANSII)));
        $this->assertSame(['Компанија'], $this->itemLabels(Menu::for($accountant, $company, PortalApp::PORTAL), 'settings'));
        $this->assertSame(['Фактурирање'], $this->itemLabels(Menu::for($accountant, $company, PortalApp::PRODAZBA), 'sales-settings'));
        $this->assertSame(['Контен план'], $this->itemLabels(Menu::for($accountant, $company, PortalApp::FINANSII), 'finance-settings'));
    }

    public function test_a_client_sees_no_finance_group_at_all(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('internal_client', $company), $company, PortalApp::FINANSII);

        $this->assertNotContains('ФИНАНСИИ', $this->groupLabels($menu));
    }

    public function test_a_client_sees_only_the_company_item_under_settings(): void
    {
        $company = Company::factory()->create();
        $client = $this->userWithRole('internal_client', $company);

        $this->assertSame(['Компанија'], $this->itemLabels(Menu::for($client, $company, PortalApp::PORTAL), 'settings'));
        $this->assertSame(['Фактурирање'], $this->itemLabels(Menu::for($client, $company, PortalApp::PRODAZBA), 'sales-settings'));
    }

    public function test_a_client_never_sees_a_naskoro_item(): void
    {
        $company = Company::factory()->create();
        $client = $this->userWithRole('internal_client', $company);

        foreach (PortalApp::cases() as $app) {
            foreach (Menu::for($client, $company, $app) as $group) {
                foreach ($group['items'] as $item) {
                    $this->assertFalse($item['soon'], "Client must not see the наскоро item {$item['label']}.");
                }
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
            $this->itemLabels(Menu::for($this->userWithRole('internal_client', $company), $company, PortalApp::PLATA), 'payroll')
        );
        $this->assertSame(
            ['Вработени', 'Плата (МПИН)', 'е-ПДД'],
            $this->itemLabels(Menu::for($this->userWithRole('admin'), $company, PortalApp::PLATA), 'payroll')
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
        $payroll = collect(Menu::for($this->userWithRole('admin'), $company, PortalApp::PLATA))->firstWhere('key', 'payroll');
        $item = collect($payroll['items'])->firstWhere('label', 'Плата (МПИН)');

        $this->assertSame('payroll-runs.*', $item['pattern']);
        $this->assertFalse($item['soon'], 'Плата (МПИН) must not be a "наскоро" stub.');
    }

    public function test_a_client_still_gets_the_full_sales_costs_and_stock_groups(): void
    {
        $company = Company::factory()->create();
        $menu = Menu::for($this->userWithRole('internal_client', $company), $company, PortalApp::PRODAZBA);

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
        $admin = $this->userWithRole('admin');

        foreach (PortalApp::cases() as $app) {
            foreach (Menu::for($admin, $company, $app) as $group) {
                foreach ($group['items'] as $item) {
                    $this->assertStringStartsWith('http', $item['url'], "{$item['label']} has no resolved URL.");
                    $this->assertNotSame('', $item['pattern'], "{$item['label']} has no route pattern.");
                }
            }
        }
    }

    public function test_korekcija_is_not_in_the_menu(): void
    {
        $company = Company::factory()->create();
        $admin = $this->userWithRole('admin');

        foreach (PortalApp::cases() as $app) {
            foreach (Menu::for($admin, $company, $app) as $group) {
                $this->assertNotContains('Корекција', array_column($group['items'], 'label'));
            }
        }
    }

    public function test_a_switched_off_module_takes_its_group_out_of_the_menu(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);

        $this->assertNotContains(
            'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ',
            $this->groupLabels(Menu::for($this->userWithRole('admin'), $company, PortalApp::PLATA))
        );
    }

    public function test_switching_off_finance_takes_the_chart_of_accounts_out_of_settings(): void
    {
        $company = Company::factory()->create(['uses_finance' => false]);
        $admin = $this->userWithRole('admin');

        $financeMenu = Menu::for($admin, $company, PortalApp::FINANSII);
        $this->assertNotContains('ФИНАНСИИ', $this->groupLabels($financeMenu));
        $this->assertNotContains('Контен план', $this->itemLabels($financeMenu, 'finance-settings'));

        // Останатите поставки остануваат — тие немаат модул.
        $this->assertContains('Компанија', $this->itemLabels(Menu::for($admin, $company, PortalApp::PORTAL), 'settings'));
    }

    public function test_partners_survive_when_material_is_switched_off(): void
    {
        // Партнерите ги бара и книжењето, не само фактурирањето, па намерно
        // немаат модул. Групата ПРОДАЖБА останува со неа единствена внатре.
        $company = Company::factory()->create(['uses_material' => false]);
        $menu = Menu::for($this->userWithRole('admin'), $company, PortalApp::PRODAZBA);

        $this->assertSame(['Кооперанти'], $this->itemLabels($menu, 'sales'));
        $this->assertNotContains('ТРОШОЦИ', $this->groupLabels($menu));
    }

    public function test_stock_disappears_with_material_even_when_its_own_flag_is_on(): void
    {
        $company = Company::factory()->create([
            'uses_material' => false,
            'uses_stock' => true,
        ]);

        $this->assertNotContains(
            'ЗАЛИХА',
            $this->groupLabels(Menu::for($this->userWithRole('admin'), $company, PortalApp::PRODAZBA))
        );
    }

    public function test_stock_can_be_switched_off_on_its_own(): void
    {
        $company = Company::factory()->create(['uses_stock' => false]);
        $menu = Menu::for($this->userWithRole('admin'), $company, PortalApp::PRODAZBA);

        $this->assertNotContains('ЗАЛИХА', $this->groupLabels($menu));
        $this->assertContains('ПРОДАЖБА', $this->groupLabels($menu));
        $this->assertContains('ТРОШОЦИ', $this->groupLabels($menu));
    }

    public function test_an_individual_profile_ignores_the_module_flags(): void
    {
        $company = Company::factory()->create([
            'type' => CompanyType::INDIVIDUAL,
            'uses_material' => false,
            'uses_finance' => false,
        ]);
        $admin = $this->userWithRole('admin');

        $this->assertSame(['ПРОДАЖБА', 'ПОСТАВКИ'], $this->groupLabels(Menu::for($admin, $company, PortalApp::PRODAZBA)));
        $this->assertSame(['БАНКАРСКИ ДОКУМЕНТИ'], $this->groupLabels(Menu::for($admin, $company, PortalApp::FINANSII)));
        $this->assertSame(['ПРИЈАВИ'], $this->groupLabels(Menu::for($admin, $company, PortalApp::PLATA)));
        $this->assertSame(['ПОСТАВКИ'], $this->groupLabels(Menu::for($admin, $company, PortalApp::PORTAL)));
    }

    public function test_each_app_shows_only_its_own_groups(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $keys = fn (PortalApp $app) => array_column(Menu::for($admin, $company, $app), 'key');

        $this->assertSame(['sales', 'costs', 'stock', 'sales-settings'], $keys(PortalApp::PRODAZBA));
        $this->assertSame(['finance', 'finance-settings'], $keys(PortalApp::FINANSII));
        $this->assertSame(['payroll', 'payroll-settings'], $keys(PortalApp::PLATA));
        $this->assertSame(['settings'], $keys(PortalApp::PORTAL));
    }

    public function test_an_individual_sees_its_own_split(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $keys = fn (PortalApp $app) => array_column(Menu::for($admin, $company, $app), 'key');

        $this->assertSame(['sales', 'sales-settings'], $keys(PortalApp::PRODAZBA));
        $this->assertSame(['bank'], $keys(PortalApp::FINANSII));
        $this->assertSame(['filings'], $keys(PortalApp::PLATA));
    }

    public function test_first_url_skips_a_coming_soon_entry(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertSame(
            route('sales-invoices.index', $company),
            Menu::firstUrl($admin, $company, PortalApp::PRODAZBA)
        );
    }

    public function test_first_url_is_null_when_the_app_has_nothing_for_this_company(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertNull(Menu::firstUrl($admin, $company, PortalApp::PLATA));
    }

    public function test_prodazba_is_entered_through_its_board_not_through_the_first_screen(): void
    {
        $company = Company::factory()->create();
        $admin = $this->userWithRole('admin');

        $this->assertSame(
            route('prodazba.dashboard', $company),
            Menu::landingUrl($admin, $company, PortalApp::PRODAZBA)
        );
    }

    public function test_apps_without_a_board_still_open_on_their_first_screen(): void
    {
        $company = Company::factory()->create();
        $admin = $this->userWithRole('admin');

        $this->assertSame(
            Menu::firstUrl($admin, $company, PortalApp::FINANSII),
            Menu::landingUrl($admin, $company, PortalApp::FINANSII)
        );
    }

    public function test_an_app_with_no_screens_at_all_has_no_landing(): void
    {
        // Без ниту еден екран ни таблата не смее да се понуди — би била
        // празна врата.
        $company = Company::factory()->create(['uses_payroll' => false]);
        $admin = $this->userWithRole('admin');

        $this->assertNull(Menu::landingUrl($admin, $company, PortalApp::PLATA));
    }
}
