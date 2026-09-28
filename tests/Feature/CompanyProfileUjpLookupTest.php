<?php

namespace Tests\Feature;

use App\Livewire\CompanyProfile;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyProfileUjpLookupTest extends TestCase
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

    public function test_check_ujp_without_a_personal_token_shows_an_error(): void
    {
        Http::fake();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::factory()->create(['tax_id' => '4030001234567']);

        Livewire::actingAs($admin)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('checkUjp')
            ->assertSet('ujpLookupError', 'Немаш регистриран е-Фактура токен на твојот профил — провери во профилот.');

        Http::assertNothingSent();
    }

    public function test_check_ujp_success_fills_the_lookup_suggestion_for_the_seller_itself(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'company' => [
                'name' => 'ТЈ ПРОСПОРТС ДООЕЛ УВОЗ-ИЗВОЗ СКОПЈЕ',
                'address' => ['street' => 'ул. Прва', 'number' => '5', 'city' => 'Скопје', 'zip' => '1000'],
            ],
        ], 200)]);
        $admin = $this->giveToken(User::factory()->create());
        $admin->assignRole('admin');
        $company = Company::factory()->create(['tax_id' => '4030001234567', 'city' => 'Скопjе (нетoчно)']);

        Livewire::actingAs($admin)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('checkUjp')
            ->assertSet('ujpLookupError', null)
            ->assertSet('ujpLookup.name', 'ТЈ ПРОСПОРТС ДООЕЛ УВОЗ-ИЗВОЗ СКОПЈЕ')
            ->call('applyUjpAddress')
            ->assertSet('editStreetAddress', 'ул. Прва')
            ->assertSet('editStreetNumber', '5')
            ->assertSet('editCity', 'Скопје')
            ->assertSet('editPostalCode', '1000');

        Http::assertSent(function ($request) {
            return $request->url() === rtrim(config('services.efaktura.base_url'), '/').'/einvoice_api/api/v1/companies/4030001234567'
                && $request->hasHeader('X-EUJP-ID', 'EUJP-1')
                && $request->hasHeader('X-EDB', '4030001234567');
        });
    }

    public function test_check_ujp_without_a_saved_tax_id_shows_an_error(): void
    {
        Http::fake();
        $admin = $this->giveToken(User::factory()->create());
        $admin->assignRole('admin');
        $company = Company::factory()->create(['tax_id' => null]);

        Livewire::actingAs($admin)
            ->test(CompanyProfile::class, ['company' => $company])
            ->call('checkUjp')
            ->assertSet('ujpLookupError', 'Фирмата нема зачувано ЕДБ.');

        Http::assertNothingSent();
    }
}
