<?php

namespace Tests\Feature;

use App\Livewire\CompanyProfile;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyProfileTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_shows_the_active_companys_name(): void
    {
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->assertSee('Alpha Ltd');
    }

    public function test_a_user_without_access_to_the_company_is_forbidden(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $otherCompany->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->assertForbidden();
    }

    public function test_the_route_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create(['name' => 'Alpha Ltd']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('companies.profile', $company))
            ->assertOk()
            ->assertSee('Alpha Ltd');
    }

    public function test_admin_sees_the_edit_button(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->assertSee('Уреди');
    }

    public function test_non_admin_does_not_see_the_edit_button(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->assertDontSee('Уреди');
    }

    public function test_non_admin_cannot_start_editing(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertForbidden();
    }

    public function test_admin_can_edit_the_companys_profile_fields(): void
    {
        $company = Company::factory()->create(['name' => 'Стара фирма ДОО']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editName', 'Ажурирана фирма ДОО')
            ->set('editShortName', 'АФ')
            ->set('editRegistrationNumber', '1234567')
            ->set('editNkdCode', '62.01')
            ->set('editNkdName', 'Компјутерско програмирање')
            ->set('editWebsite', 'https://example.mk')
            ->set('editDirectorName', 'Марко Марковски')
            ->set('editDirectorPhone', '070123456')
            ->set('editDirectorEmail', 'marko@example.mk')
            ->set('editIsVatRegistered', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Ажурирана фирма ДОО',
            'short_name' => 'АФ',
            'registration_number' => '1234567',
            'nkd_code' => '62.01',
            'nkd_name' => 'Компјутерско програмирање',
            'website' => 'https://example.mk',
            'director_name' => 'Марко Марковски',
            'director_phone' => '070123456',
            'director_email' => 'marko@example.mk',
            'is_vat_registered' => false,
        ]);
    }

    public function test_editing_the_profile_requires_a_name(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editName', '')
            ->call('save')
            ->assertHasErrors(['editName' => 'required']);
    }

    public function test_non_admin_cannot_call_save(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('save')
            ->assertForbidden();
    }

    public function test_starting_edit_seeds_one_blank_bank_account_row_when_none_exist(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertSet('bankAccounts', [['bank_name' => '', 'account_number' => '']]);
    }

    public function test_filling_the_last_bank_account_row_reveals_a_new_blank_row(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('bankAccounts.0.account_number', 'MK07300701104789126');

        $this->assertCount(2, $component->get('bankAccounts'));
    }

    public function test_bank_account_rows_are_capped_at_five(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit');

        foreach (range(0, 4) as $index) {
            $component->set("bankAccounts.$index.account_number", "MK0{$index}00000000000000");
        }

        $this->assertCount(5, $component->get('bankAccounts'));
    }

    public function test_admin_can_save_multiple_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('bankAccounts.0.bank_name', 'Комерцијална банка')
            ->set('bankAccounts.0.account_number', 'MK07300701104789126')
            ->set('bankAccounts.1.bank_name', 'НЛБ Банка')
            ->set('bankAccounts.1.account_number', 'MK07200002785123453')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('company_bank_accounts', [
            'company_id' => $company->id,
            'bank_name' => 'Комерцијална банка',
            'account_number' => 'MK07300701104789126',
            'position' => 0,
        ]);
        $this->assertDatabaseHas('company_bank_accounts', [
            'company_id' => $company->id,
            'bank_name' => 'НЛБ Банка',
            'account_number' => 'MK07200002785123453',
            'position' => 1,
        ]);
        $this->assertDatabaseCount('company_bank_accounts', 2);
    }

    public function test_saving_replaces_the_previous_set_of_bank_accounts(): void
    {
        $company = Company::factory()->create();
        $company->bankAccounts()->create(['bank_name' => 'Стара банка', 'account_number' => 'MK00OLD00000000000', 'position' => 0]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertSet('bankAccounts.0.account_number', 'MK00OLD00000000000')
            ->set('bankAccounts.0.bank_name', 'Нова банка')
            ->set('bankAccounts.0.account_number', 'MK00NEW00000000000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('company_bank_accounts', ['account_number' => 'MK00OLD00000000000']);
        $this->assertDatabaseHas('company_bank_accounts', [
            'company_id' => $company->id,
            'bank_name' => 'Нова банка',
            'account_number' => 'MK00NEW00000000000',
        ]);
    }

    public function test_a_blank_trailing_row_is_not_saved(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('bankAccounts.0.bank_name', 'Комерцијална банка')
            ->set('bankAccounts.0.account_number', 'MK07300701104789126')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('company_bank_accounts', 1);
    }

    public function test_admin_can_upload_a_logo_and_set_its_position(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $file = UploadedFile::fake()->image('logo.png');

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('newLogo', $file)
            ->set('editLogoPosition', 'center')
            ->call('save')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertNotNull($company->logo_path);
        Storage::disk('public')->assertExists($company->logo_path);
        $this->assertEquals('center', $company->logo_position);
    }

    public function test_admin_can_save_the_invoice_footer_note(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editInvoiceFooterNote', 'Ви благодариме за соработката.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'invoice_footer_note' => 'Ви благодариме за соработката.',
        ]);
    }

    public function test_logo_position_defaults_to_left_when_editing_an_untouched_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Livewire::test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertSet('editLogoPosition', 'left');
    }
}
