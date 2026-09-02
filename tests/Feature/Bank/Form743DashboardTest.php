<?php

namespace Tests\Feature\Bank;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\Form743;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Form743DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function individual(): Company
    {
        return Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_the_tiles_count_received_pending_and_filed(): void
    {
        $company = $this->individual();
        Form743::factory()->for($company)->count(3)->create(['created_at' => now()]);
        Form743::factory()->for($company)->filed()->count(2)->create(['created_at' => now()]);
        $this->actingAs($this->admin());

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertViewHas('form743Counts', [
                'received' => 5,
                'pending' => 3,
                'filed' => 2,
            ]);
    }

    /**
     * Обрасците од друг клиент немаат работа на овој екран.
     */
    public function test_another_clients_forms_are_not_counted(): void
    {
        $company = $this->individual();
        Form743::factory()->for($company)->create(['created_at' => now()]);
        Form743::factory()->for($this->individual())->count(4)->create(['created_at' => now()]);
        $this->actingAs($this->admin());

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertViewHas('form743Counts', fn (array $counts) => $counts['received'] === 1);
    }

    /**
     * Плочките ја следат работната година како и приходот над нив — инаку
     * секој јануари екранот би покажувал лански бројки.
     */
    public function test_a_form_from_another_year_stays_out(): void
    {
        $company = $this->individual();
        Form743::factory()->for($company)->create(['created_at' => now()]);
        Form743::factory()->for($company)->create(['created_at' => now()->subYears(2)]);
        $this->actingAs($this->admin());

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertViewHas('form743Counts', fn (array $counts) => $counts['received'] === 1);
    }

    public function test_a_client_with_nothing_uploaded_sees_zeroes_not_an_error(): void
    {
        $company = $this->individual();
        $this->actingAs($this->admin());

        Livewire::test(CompanyDashboard::class, ['company' => $company])
            ->assertViewHas('form743Counts', [
                'received' => 0,
                'pending' => 0,
                'filed' => 0,
            ])
            ->assertSee('Износ на ДЛД')
            ->assertSee('наскоро');
    }
}
