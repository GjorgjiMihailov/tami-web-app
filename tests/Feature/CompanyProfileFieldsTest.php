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
        $company = Company::factory()->create(['type' => CompanyType::LEGAL, 'embg' => null]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEmbg', 'ABCDEFGHIJKLM')
            ->call('save')
            ->assertHasNoErrors();

        // „Игнорира" значи дека вредноста не завршува во базата, не само дека
        // не паѓа на валидација. ЕМБГ е поле на физичко лице, исто како што
        // ЕДБ и НКД се полиња на правно лице.
        $this->assertNull($company->fresh()->embg);
    }

    /**
     * editTaxId е јавно Livewire својство — може да се постави преку жица
     * без разлика што прикажува Blade-от. Формата го крие полето ЕДБ за
     * физичко лице по зачувувањето, па вредност запишана овде би била
     * недостапна за прегледување или бришење. Затоа не смее да се запише.
     */
    public function test_an_individual_profile_ignores_a_tax_id_value(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL, 'tax_id' => null]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editTaxId', '4012345678901')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($company->fresh()->tax_id);
    }

    public function test_a_legal_profile_still_saves_its_tax_id(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL, 'tax_id' => null]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editTaxId', '4012345678901')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('4012345678901', $company->fresh()->tax_id);
    }

    /**
     * Формата за уредување секогаш испраќа вредност за е-Фактура режимот, МПИН
     * обврзникот и слични полиња — дури и стандардна вредност како "firm" —
     * бидејќи тие се полиња на компонентата, не празни низи. За физичко лице
     * тие полиња се скриени во формата и немаат смисла (нема ЕДБ = нема
     * е-Фактура пристап), па зачувувањето не смее да ги допре: секое уредување
     * на несврзано поле не смее да ги презапише со стандардна вредност.
     */
    public function test_an_individual_profile_saves_without_touching_the_company_only_columns(): void
    {
        $company = Company::factory()->create([
            'type' => CompanyType::INDIVIDUAL,
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-KEEP-ME',
            'mpin_obvrznik_code' => null,
            'is_vat_registered' => false,
        ]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editPhone', '070999888')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $company->fresh();
        $this->assertSame('070999888', $fresh->phone);
        $this->assertSame(Company::EFAKTURA_MODE_OWN, $fresh->efaktura_credential_mode);
        $this->assertSame('EUJP-KEEP-ME', $fresh->efaktura_eujp_id);
        $this->assertNull($fresh->mpin_obvrznik_code);
        $this->assertFalse($fresh->is_vat_registered);
    }

    public function test_a_legal_profile_still_saves_its_efaktura_mode(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->set('editEfakturaMode', Company::EFAKTURA_MODE_OWN)
            ->set('editEfakturaEujpId', 'EUJP-123')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $company->fresh();
        $this->assertSame(Company::EFAKTURA_MODE_OWN, $fresh->efaktura_credential_mode);
        $this->assertSame('EUJP-123', $fresh->efaktura_eujp_id);
    }

    public function test_an_individual_profile_is_not_called_a_company(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertSee('Профил на физичко лице')
            ->assertDontSee('Профил на фирма');
    }

    public function test_a_legal_profile_is_still_called_a_company(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::LEGAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertSee('Профил на фирма');
    }

    public function test_an_individual_profile_does_not_show_the_mpin_or_efaktura_sections(): void
    {
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);

        Livewire::actingAs($this->admin())
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('startEdit')
            ->assertDontSee('МПИН')
            ->assertDontSee('е-Фактура акредитиви');
    }
}
