<?php

namespace Tests\Feature;

use App\Models\Company;
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
        Role::findOrCreate('client');
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
        $client->assignRole('client');

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
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('sales-invoices.index', $company))
            ->assertOk()
            ->assertSee(route('prodazba.dashboard', $company), false)
            ->assertSee('Табла');
    }

    public function test_other_apps_have_no_board_link_in_their_sidebar(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('accounting.journal-groups.index', $company))
            ->assertOk()
            ->assertDontSee(route('prodazba.dashboard', $company), false);
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
}
