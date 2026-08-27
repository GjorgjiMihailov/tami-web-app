<?php

namespace Tests\Feature;

use App\Livewire\CompanyDashboard;
use App\Models\Company;
use App\Models\User;
use App\Support\Payroll\MpinObvrznik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardMpinObvrznikTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_admin_can_save_the_mpin_obvrznik_type(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['mpin_obvrznik_code' => null]);

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editMpinObvrznikCode', '111')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertSame(MpinObvrznik::SELF_EMPLOYED, $company->mpin_obvrznik_code);
    }

    public function test_an_unsupported_obvrznik_type_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create();

        Livewire::actingAs($admin)
            ->test(CompanyDashboard::class, ['company' => $company])
            ->call('startEdit')
            ->set('editMpinObvrznikCode', '115')
            ->call('save')
            ->assertHasErrors('editMpinObvrznikCode');
    }
}
