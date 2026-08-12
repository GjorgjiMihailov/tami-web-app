<?php

namespace Tests\Feature;

use App\Livewire\Reports\ReportIndex;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_links_to_the_three_working_reports(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(ReportIndex::class, ['company' => $company])
            ->assertSeeHtml(route('reports.ddv04', $company))
            ->assertSeeHtml(route('accounting.reports.trial-balance', $company))
            ->assertSeeHtml(route('accounting.reports.ledger-card', $company));
    }

    public function test_it_names_the_three_reports_that_are_not_built_yet(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(ReportIndex::class, ['company' => $company])
            ->assertSee('МДБ')
            ->assertSee('Завршна сметка')
            ->assertSee('Солвентност')
            ->assertSee('наскоро');
    }

    public function test_the_page_renders_over_http_for_an_admin(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('reports.index', $company))->assertOk();
    }

    public function test_a_client_is_refused(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client)->get(route('reports.index', $company))->assertForbidden();
    }
}
