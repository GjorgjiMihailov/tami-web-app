<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Панелот „АПЛИКАЦИИ" (resources/views/livewire/layout/navigation.blade.php)
 * секогаш мора да нуди пат назад кон порталот:
 * - администратор гледа „Портал — клиенти и поставки" (route('clients.index'));
 * - секој друг, кога има фирма во контекст, гледа „Портал — табла на фирмата"
 *   (route('companies.dashboard', $company));
 * - секој друг, без фирма во контекст, нема линк воопшто.
 *
 * Пред оваа задача, companies.index е admin-само рута, па клиент или
 * сметководител внатре во апликација немаше воопшто пат назад до порталот.
 */
class NavigationPortalLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    public function test_a_non_admin_with_a_company_in_context_sees_the_dashboard_link_not_companies_index(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $response = $this->actingAs($client)->get(route('sales-invoices.index', $company));

        $response->assertOk();
        $response->assertSee('Портал — табла на фирмата');
        $response->assertSeeHtml('href="'.route('companies.dashboard', $company).'"');

        $response->assertDontSee('Портал — клиенти и поставки');
        // Совпаѓа со целата адреса, не со префикс — /companies е префикс на
        // секоја адреса во контекст на фирма, па само зборот не докажува ништо.
        $response->assertDontSeeHtml('href="'.route('clients.index').'"');
    }

    public function test_an_accountant_with_a_company_in_context_sees_the_dashboard_link_not_companies_index(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $response = $this->actingAs($accountant)->get(route('companies.dashboard', $company));

        $response->assertOk();
        $response->assertSee('Портал — табла на фирмата');
        $response->assertDontSee('Портал — клиенти и поставки');
        $response->assertDontSeeHtml('href="'.route('clients.index').'"');
    }

    public function test_an_admin_still_sees_clients_index_not_the_dashboard_link(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('companies.dashboard', $company));

        $response->assertOk();
        $response->assertSee('Портал — клиенти и поставки');
        $response->assertSeeHtml('href="'.route('clients.index').'"');
        $response->assertDontSee('Портал — табла на фирмата');
    }

    public function test_a_non_admin_with_no_company_in_context_sees_no_portal_link(): void
    {
        // Две видливи фирми: App\Livewire\Dashboard::companyToOpen() не може
        // да погоди која, па паѓа на render() од екранот за избор — рутата
        // 'dashboard' нема {company} параметар, значи нема фирма во контекст
        // на панелот.
        //
        // Порано тука стоеше сметководител со НУЛА фирми. Тој веќе не останува
        // на dashboard: сега се пренасочува на екранот за прв клиент
        // (App\Livewire\FirstClient). Правилото што се проверува е непроменето
        // — сменет е само човекот што го доведува екранот во таа состојба.
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        Company::factory()->create()->accountants()->attach($accountant);
        Company::factory()->create()->accountants()->attach($accountant);

        $response = $this->actingAs($accountant)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Портал — клиенти и поставки');
        $response->assertDontSee('Портал — табла на фирмата');
    }

    public function test_the_first_client_screen_has_no_portal_link_either(): void
    {
        // Нула фирми значи и празен AppSwitcher, па копчето АПЛИКАЦИИ воопшто
        // не се црта — а со него ниту врската кон порталот.
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');

        $response = $this->actingAs($accountant)->get(route('onboarding.first-client'));

        $response->assertOk();
        $response->assertDontSee('Портал — клиенти и поставки');
        $response->assertDontSee('Портал — табла на фирмата');
    }
}
