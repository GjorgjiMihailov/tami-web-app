<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\CompanyType;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_the_board_lives_on_the_prodazba_host(): void
    {
        $company = Company::factory()->create();

        $this->assertStringStartsWith(
            'http://'.PortalApp::PRODAZBA->domain(),
            route('prodazba.dashboard', $company)
        );
    }

    public function test_the_board_opens_and_names_the_company(): void
    {
        $company = Company::factory()->create(['name' => 'ТЕСТ ДООЕЛ']);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertOk()
            ->assertSee('ТЕСТ ДООЕЛ');
    }

    public function test_no_blade_component_is_left_uncompiled(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertDontSee('<x-', false);
    }

    public function test_the_board_survives_material_being_switched_off(): void
    {
        // Кооперанти немаат модул, па таблата мора да се отвори и кога
        // Материјално е исклучено. Затоа рутата НЕ носи EnsureCompanyModule.
        $company = Company::factory()->create(['uses_material' => false]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertOk();
    }

    public function test_a_stranger_may_not_open_someone_elses_board(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $mine->id]);
        $client->assignRole('internal_client');

        $this->actingAs($client)
            ->get(route('prodazba.dashboard', $theirs))
            ->assertForbidden();
    }

    public function test_the_route_requires_authentication(): void
    {
        $company = Company::factory()->create();

        $this->get(route('prodazba.dashboard', $company))->assertRedirect();
    }

    public function test_the_sidebar_carries_a_link_to_the_board(): void
    {
        // Се бара класата sidebar-board, не голата адреса: панелот АПЛИКАЦИИ
        // ја носи истата адреса на СЕКОЈА страна од апликацијата, па проверка
        // на адресата не разликува мени од панел.
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('sales-invoices.index', $company))
            ->assertOk()
            ->assertSee('sidebar-board', false)
            ->assertSee('Дома');
    }

    public function test_other_apps_have_no_board_link_in_their_sidebar(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('accounting.journal-groups.index', $company))
            ->assertOk()
            ->assertDontSee('sidebar-board', false);
    }

    /**
     * Ознаката на копче на таблата, како што излегува во HTML.
     *
     * Гола проверка на текст („Излезни фактури") НЕ важи тука: истите зборови
     * стојат и во страничното мени на секоја страна од Продажба, па тестот би
     * поминувал и кога таблата е празна. Затоа се бара класата на копчето.
     */
    private function boardButton(string $label): string
    {
        return '<span class="board-link__label">'.$label.'</span>';
    }

    public function test_a_legal_entity_with_everything_on_gets_all_three_buttons(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSee($this->boardButton('Излезни фактури'), false)
            ->assertSee($this->boardButton('Влезни фактури'), false)
            ->assertSee($this->boardButton('Кооперанти'), false);
    }

    public function test_with_material_off_only_the_partners_button_is_left(): void
    {
        // Копче кон екран затворен со EnsureCompanyModule завршува со
        // „Забранет пристап" — полошо од отсутно копче.
        $company = Company::factory()->create(['uses_material' => false]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertDontSee($this->boardButton('Излезни фактури'), false)
            ->assertDontSee($this->boardButton('Влезни фактури'), false)
            ->assertSee($this->boardButton('Кооперанти'), false);
    }

    public function test_an_individual_has_no_incoming_invoices_at_all(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSee($this->boardButton('Излезни фактури'), false)
            ->assertDontSee($this->boardButton('Влезни фактури'), false)
            ->assertSee($this->boardButton('Кооперанти'), false);
    }

    public function test_each_button_points_at_its_own_screen(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSee(route('sales-invoices.index', $company), false)
            ->assertSee(route('purchase-invoices.index', $company), false)
            ->assertSee(route('partners.index', $company), false);
    }

    public function test_the_buttons_carry_the_motion_class(): void
    {
        // Однесувањето живее во resources/css/app.css; изгледот само ја носи
        // куката. assertSee на текст не би забележал изгубена класа.
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSee('board-link board-link--orange', false);
    }

    /** Партнер на оваа фирма, со дадено име. */
    private function partnerNamed(Company $company, string $name): Partner
    {
        return Partner::factory()->create(['company_id' => $company->id, 'name' => $name]);
    }

    public function test_only_the_five_latest_entered_sales_invoices_are_shown(): void
    {
        $company = Company::factory()->create();

        // Шест фактури, внесени по ред. Првата внесена мора да испадне.
        foreach (range(1, 6) as $i) {
            SalesInvoice::factory()->create([
                'company_id' => $company->id,
                'partner_id' => $this->partnerNamed($company, "КУПУВАЧ {$i} ДООЕЛ")->id,
                'created_at' => now()->addMinutes($i),
            ]);
        }

        $response = $this->actingAs($this->admin())->get(route('prodazba.dashboard', $company));

        $response->assertOk();
        $response->assertSee('Последни излезни фактури');
        $response->assertSee('КУПУВАЧ 6 ДООЕЛ');
        $response->assertSee('КУПУВАЧ 2 ДООЕЛ');
        $response->assertDontSee(
            'КУПУВАЧ 1 ДООЕЛ',
            'Најстарата по внесување мора да испадне од петте.'
        );
    }

    public function test_the_order_is_by_entry_not_by_invoice_date(): void
    {
        // Побарано е „последните пет што се ВНЕСЕНИ". Скенирана фактура од
        // минатиот месец, внесена денес, припаѓа на врвот.
        $company = Company::factory()->create();

        SalesInvoice::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $this->partnerNamed($company, 'ВНЕСЕНА ПРВА')->id,
            'invoice_date' => now()->toDateString(),
            'created_at' => now()->subHour(),
        ]);

        SalesInvoice::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $this->partnerNamed($company, 'ВНЕСЕНА ВТОРА')->id,
            'invoice_date' => now()->subMonth()->toDateString(),
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSeeInOrder(['ВНЕСЕНА ВТОРА', 'ВНЕСЕНА ПРВА']);
    }

    public function test_only_invoices_from_the_working_year_are_listed(): void
    {
        $company = Company::factory()->create();

        SalesInvoice::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $this->partnerNamed($company, 'ЛАНСКИ КУПУВАЧ')->id,
            'invoice_date' => now()->subYear()->toDateString(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('prodazba.dashboard', $company));

        $response->assertOk();

        // Фактурата постои, но табелата е празна — тоа е доказот дека филтерот
        // по година работи. Името на партнерот НЕ се проверува: тој се појавува
        // во табелата „Кооперанти", која намерно нема година.
        $response->assertSee('Нема внесени излезни фактури');
    }

    public function test_incoming_invoices_have_their_own_table(): void
    {
        $company = Company::factory()->create();

        PurchaseInvoice::factory()->create([
            'company_id' => $company->id,
            'partner_id' => $this->partnerNamed($company, 'ДОБАВУВАЧ ДООЕЛ')->id,
        ]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSee('Последни влезни фактури')
            ->assertSee('ДОБАВУВАЧ ДООЕЛ');
    }

    public function test_partners_are_listed_regardless_of_the_working_year(): void
    {
        $company = Company::factory()->create();
        $this->partnerNamed($company, 'КООПЕРАНТ ДООЕЛ');

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertOk()
            ->assertSee('КООПЕРАНТ ДООЕЛ');
    }

    public function test_another_companys_records_never_appear(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $this->partnerNamed($theirs, 'ТУЃ КООПЕРАНТ');

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $mine))
            ->assertDontSee('ТУЃ КООПЕРАНТ');
    }

    public function test_a_table_whose_button_is_hidden_is_hidden_too(): void
    {
        $company = Company::factory()->create(['uses_material' => false]);

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertDontSee('Последни излезни фактури')
            ->assertDontSee('Последни влезни фактури')
            ->assertSee('Кооперанти');
    }

    public function test_an_empty_table_says_so_instead_of_gaping(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('prodazba.dashboard', $company))
            ->assertSee('Нема внесени излезни фактури')
            ->assertSee('Нема внесени влезни фактури')
            ->assertSee('Нема внесени кооперанти');
    }
}
