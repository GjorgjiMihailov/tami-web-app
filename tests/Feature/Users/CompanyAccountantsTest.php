<?php

namespace Tests\Feature\Users;

use App\Livewire\CompanyUsers;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyAccountantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_an_admin_assigns_and_removes_an_accountant(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->userWithRole('accountant');

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('assignAccountant', $accountant->id)
            ->assertHasNoErrors();

        $this->assertTrue($company->fresh()->accountants->contains($accountant));

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('removeAccountant', $accountant->id)
            ->assertHasNoErrors();

        $this->assertFalse($company->fresh()->accountants->contains($accountant));
    }

    public function test_assigning_twice_does_not_duplicate_the_row(): void
    {
        $company = Company::factory()->create();
        $accountant = $this->userWithRole('accountant');

        Livewire::actingAs($this->userWithRole('admin'))
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('assignAccountant', $accountant->id)
            ->call('assignAccountant', $accountant->id);

        $this->assertSame(1, $company->fresh()->accountants->count());
    }

    public function test_only_accountants_can_be_assigned(): void
    {
        $company = Company::factory()->create();
        $client = $this->userWithRole('client');

        // findOrFail фрла, не враќа одговор — затоа исклучокот се фаќа, а
        // тврдењето за базата останува во finally.
        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::actingAs($this->userWithRole('admin'))
                ->test(CompanyUsers::class, ['company' => $company])
                ->call('assignAccountant', $client->id);
        } finally {
            $this->assertSame(0, $company->fresh()->accountants->count());
        }
    }

    public function test_a_client_cannot_assign_an_accountant(): void
    {
        $company = Company::factory()->create();
        $client = $this->userWithRole('client');
        $client->forceFill(['company_id' => $company->id])->save();
        $accountant = $this->userWithRole('accountant');

        Livewire::actingAs($client)
            ->test(CompanyUsers::class, ['company' => $company])
            ->call('assignAccountant', $accountant->id)
            ->assertForbidden();

        $this->assertSame(0, $company->fresh()->accountants->count());
    }
}
