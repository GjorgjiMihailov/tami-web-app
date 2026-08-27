<?php

namespace Tests\Feature;

use App\Livewire\CompanyProfile;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyProfileFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_an_individual_profile_stores_a_valid_embg(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEmbg', '3101980455019')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('3101980455019', $company->fresh()->embg);
    }

    public function test_an_invalid_embg_is_rejected(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEmbg', '1234567890123')
            ->call('save')
            ->assertHasErrors('editEmbg');
    }

    public function test_a_legal_profile_does_not_show_the_embg_field(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertDontSee('ЕМБГ');
    }

    public function test_an_individual_profile_does_not_show_the_company_only_fields(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertDontSee('НКД')
            ->assertDontSee('Директор');
    }

    /**
     * ЕМБГ е задолжителен само во смисла дека, ако се внесе, мора да е валиден
     * — не е задолжителен за секое зачувување. Профил на физичко лице создаден
     * пред ова поле да постои нема ЕМБГ, па првото уредување (на пр. само
     * телефонскиот број) не смее да биде блокирано со барање да се внесе ЕМБГ
     * веднаш. Ова е свесно отстапување од бришевата формулација "required".
     */
    public function test_an_individual_profile_can_be_saved_without_an_embg_yet(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL, 'embg' => null]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editPhone', '070111222')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($company->fresh()->embg);
        $this->assertSame('070111222', $company->fresh()->phone);
    }

    public function test_a_legal_profile_ignores_an_embg_value(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEmbg', 'ABCDEFGHIJKLM')
            ->call('save')
            ->assertHasNoErrors();
    }
}
