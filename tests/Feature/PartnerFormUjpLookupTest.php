<?php

namespace Tests\Feature;

use App\Livewire\PartnerForm;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerFormUjpLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    /** Токенот е личен — на човекот што потпишува, не на фирмата. */
    private function giveToken(User $user): User
    {
        $user->forceFill(['efaktura_eujp_id' => 'EUJP-1', 'efaktura_token_serial_number' => '1A2B3C'])->save();

        return $user->fresh();
    }

    private function admin(bool $withToken = true): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        if ($withToken) {
            $admin = $this->giveToken($admin);
        }
        $this->actingAs($admin);

        return $admin;
    }

    public function test_check_ujp_without_tax_id_shows_an_error_and_does_not_call_ujp(): void
    {
        Http::fake();
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('taxId', '')
            ->call('checkUjp')
            ->assertSet('ujpLookupError', 'Внеси прво ЕДБ.')
            ->assertSet('ujpLookup', null);

        Http::assertNothingSent();
    }

    public function test_check_ujp_without_a_personal_token_shows_an_error(): void
    {
        Http::fake();
        $company = Company::factory()->create();
        $this->admin(withToken: false);

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('taxId', '4001235555678')
            ->call('checkUjp')
            ->assertSet('ujpLookupError', 'Немаш регистриран е-Фактура токен на твојот профил — провери во профилот.');

        Http::assertNothingSent();
    }

    public function test_check_ujp_success_fills_the_lookup_suggestion_with_ujps_official_name(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'company' => [
                'name' => 'ТЈ ПРОСПОРТС ДООЕЛ УВОЗ-ИЗВОЗ СКОПЈЕ',
                'address' => ['street' => 'ул. Прва', 'number' => '5', 'city' => 'Скопје', 'zip' => '1000'],
            ],
        ], 200)]);
        $company = Company::factory()->create(['tax_id' => '4030001234567']);
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('name', 'ТЈ ПРОСПОРТС ДООЕЛ Скопје')
            ->set('taxId', '4001235555678')
            ->call('checkUjp')
            ->assertSet('ujpLookupError', null)
            ->assertSet('ujpLookup.name', 'ТЈ ПРОСПОРТС ДООЕЛ УВОЗ-ИЗВОЗ СКОПЈЕ')
            ->assertSet('name', 'ТЈ ПРОСПОРТС ДООЕЛ Скопје') // applyUjpName not clicked yet — no silent overwrite
            ->call('applyUjpName')
            ->assertSet('name', 'ТЈ ПРОСПОРТС ДООЕЛ УВОЗ-ИЗВОЗ СКОПЈЕ')
            ->call('applyUjpAddress')
            ->assertSet('streetAddress', 'ул. Прва')
            ->assertSet('streetNumber', '5')
            ->assertSet('city', 'Скопје')
            ->assertSet('postalCode', '1000');

        Http::assertSent(function ($request) use ($company) {
            return $request->url() === rtrim(config('services.efaktura.base_url'), '/').'/einvoice_api/api/v1/companies/4001235555678'
                && $request->hasHeader('X-EUJP-ID', 'EUJP-1')
                && $request->hasHeader('X-EDB', $company->tax_id);
        });
    }

    public function test_check_ujp_surfaces_ujps_own_error_message(): void
    {
        Http::fake(['*' => Http::response([
            'success' => false,
            'errorStatus' => ['errorCode' => 'E10001', 'errorMessage' => 'Company not found'],
        ], 200)]);
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('taxId', '0000000000000')
            ->call('checkUjp')
            ->assertSet('ujpLookupError', 'Company not found')
            ->assertSet('ujpLookup', null);
    }
}
