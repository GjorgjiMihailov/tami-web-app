<?php

namespace Tests\Feature\Costs;

use App\Livewire\Costs\OtherCostIndex;
use App\Models\Company;
use App\Models\OtherCost;
use App\Models\User;
use App\Support\CompanyType;
use App\Support\WorkingYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OtherCostIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
        // Работната година се изведува од денешниот датум кога фирмата нема
        // сопствен избор, па тестовите го фиксираат за да не се менуваат сами
        // на 1 јануари.
        Carbon::setTestNow('2026-06-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_a_cost_is_saved_with_its_document(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(OtherCostIndex::class, ['company' => $company])
            ->set('costDate', '2026-04-02')
            ->set('description', 'Гориво, фискална сметка бр. 118')
            ->set('amount', '1250.50')
            ->set('newFile', UploadedFile::fake()->create('smetka-118.pdf', 20))
            ->call('save')
            ->assertHasNoErrors();

        $cost = OtherCost::where('company_id', $company->id)->firstOrFail();

        $this->assertSame('2026-04-02', $cost->cost_date->toDateString());
        $this->assertSame('Гориво, фискална сметка бр. 118', $cost->description);
        $this->assertSame('1250.50', $cost->amount);

        $document = $cost->documents()->firstOrFail();
        $this->assertSame('smetka-118.pdf', $document->original_filename);
        Storage::disk('google')->assertExists($document->path);
    }

    /**
     * Документот не е задолжителен — фискалната сметка понекогаш се качува
     * подоцна, а трошокот сепак треба да е запишан.
     */
    public function test_a_cost_may_be_saved_without_a_document(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(OtherCostIndex::class, ['company' => $company])
            ->set('costDate', '2026-04-02')
            ->set('description', 'Паркинг')
            ->set('amount', '60')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, OtherCost::where('company_id', $company->id)->count());
    }

    public function test_an_empty_form_is_refused(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(OtherCostIndex::class, ['company' => $company])
            ->set('costDate', '')
            ->call('save')
            ->assertHasErrors(['costDate', 'description', 'amount']);

        $this->assertSame(0, OtherCost::count());
    }

    public function test_an_amount_of_zero_is_refused(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(OtherCostIndex::class, ['company' => $company])
            ->set('costDate', '2026-04-02')
            ->set('description', 'Ништо')
            ->set('amount', '0')
            ->call('save')
            ->assertHasErrors('amount');

        $this->assertSame(0, OtherCost::count());
    }

    /**
     * Запис надвор од работната година веднаш би исчезнал од списокот и би
     * изгледал како да не се зачувал.
     */
    public function test_a_date_outside_the_working_year_is_refused(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        Livewire::test(OtherCostIndex::class, ['company' => $company])
            ->set('costDate', '2025-12-31')
            ->set('description', 'Гориво')
            ->set('amount', '500')
            ->call('save')
            ->assertHasErrors('costDate');

        $this->assertSame(0, OtherCost::count());
    }

    public function test_the_list_shows_only_this_company_and_this_year(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();

        OtherCost::factory()->for($company)->create(['cost_date' => '2026-02-01', 'amount' => '100.00']);
        OtherCost::factory()->for($company)->create(['cost_date' => '2025-11-01', 'amount' => '999.00']);
        OtherCost::factory()->for($other)->create(['cost_date' => '2026-02-01', 'amount' => '777.00']);

        $this->actingAs($this->admin());

        Livewire::test(OtherCostIndex::class, ['company' => $company])
            ->assertViewHas('costs', fn ($costs) => $costs->count() === 1
                && $costs->first()->amount === '100.00');
    }

    /**
     * Декемвриски трошок мора да остане во годината. Оваа проектна база веќе
     * еднаш се сопна на тоа: со `date` cast SQLite запишува и време, па
     * споредба со низа што завршува на '-12-31' го исфрла записот.
     */
    public function test_a_cost_dated_the_last_day_of_the_year_stays_in_the_list(): void
    {
        $company = Company::factory()->create();
        OtherCost::factory()->for($company)->create(['cost_date' => '2026-12-31', 'amount' => '10.00']);
        $this->actingAs($this->admin());

        Livewire::test(OtherCostIndex::class, ['company' => $company])
            ->assertViewHas('costs', fn ($costs) => $costs->count() === 1);
    }

    public function test_the_total_matches_the_listed_costs(): void
    {
        $company = Company::factory()->create();
        OtherCost::factory()->for($company)->create(['cost_date' => '2026-01-10', 'amount' => '1200.55']);
        OtherCost::factory()->for($company)->create(['cost_date' => '2026-02-10', 'amount' => '300.45']);
        OtherCost::factory()->for($company)->create(['cost_date' => '2025-02-10', 'amount' => '5000.00']);
        $this->actingAs($this->admin());

        Livewire::test(OtherCostIndex::class, ['company' => $company])
            ->assertViewHas('total', '1501.00');
    }

    public function test_the_newest_cost_comes_first(): void
    {
        $company = Company::factory()->create();
        $older = OtherCost::factory()->for($company)->create(['cost_date' => '2026-01-10']);
        $newer = OtherCost::factory()->for($company)->create(['cost_date' => '2026-05-10']);
        $this->actingAs($this->admin());

        Livewire::test(OtherCostIndex::class, ['company' => $company])
            ->assertViewHas('costs', fn ($costs) => $costs->first()->id === $newer->id
                && $costs->last()->id === $older->id);
    }

    public function test_an_individual_profile_may_not_open_the_screen(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        $this->actingAs($this->admin())
            ->get(route('other-costs.index', $company))
            ->assertForbidden();
    }

    public function test_a_legal_profile_may_open_the_screen(): void
    {
        $this->actingAs($this->admin())
            ->get(route('other-costs.index', Company::factory()->create()))
            ->assertOk();
    }

    /**
     * Клиентот сам ги качува своите фискални сметки — истата поделба како кај
     * влезните фактури.
     */
    public function test_a_client_may_enter_their_own_costs(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(OtherCostIndex::class, ['company' => $company])
            ->set('costDate', '2026-03-03')
            ->set('description', 'Тонер')
            ->set('amount', '900')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, OtherCost::where('company_id', $company->id)->count());
    }

    public function test_the_form_opens_on_a_date_inside_the_working_year(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->admin());

        $component = Livewire::test(OtherCostIndex::class, ['company' => $company]);

        $this->assertSame(
            WorkingYear::defaultDate($component->get('workingYear')),
            $component->get('costDate')
        );
    }
}
