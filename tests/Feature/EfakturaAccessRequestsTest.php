<?php

namespace Tests\Feature;

use App\Livewire\EfakturaAccessRequests;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EfakturaAccessRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_admin_sees_only_requested_companies(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $requested = Company::factory()->create([
            'name' => 'Побарана',
            'efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REQUESTED,
        ]);
        Company::factory()->create(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_NONE]);

        Livewire::actingAs($admin)
            ->test(EfakturaAccessRequests::class)
            ->assertSee('Побарана')
            ->assertSeeHtml('wire:click="approve('.$requested->id.')"');
    }

    public function test_admin_can_approve_a_request(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REQUESTED]);

        Livewire::actingAs($admin)
            ->test(EfakturaAccessRequests::class)
            ->call('approve', $company->id);

        $company->refresh();
        $this->assertSame(Company::EFAKTURA_STATUS_APPROVED, $company->efaktura_firm_access_status);
        $this->assertSame($admin->id, $company->efaktura_firm_access_decided_by);
        $this->assertNotNull($company->efaktura_firm_access_decided_at);
    }

    public function test_admin_can_reject_a_request(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['efaktura_firm_access_status' => Company::EFAKTURA_STATUS_REQUESTED]);

        Livewire::actingAs($admin)
            ->test(EfakturaAccessRequests::class)
            ->call('reject', $company->id);

        $company->refresh();
        $this->assertSame(Company::EFAKTURA_STATUS_REJECTED, $company->efaktura_firm_access_status);
        $this->assertSame($admin->id, $company->efaktura_firm_access_decided_by);
        $this->assertNotNull($company->efaktura_firm_access_decided_at);
    }

    public function test_non_admin_cannot_view_the_screen(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');

        Livewire::actingAs($client)->test(EfakturaAccessRequests::class)->assertForbidden();
    }
}
