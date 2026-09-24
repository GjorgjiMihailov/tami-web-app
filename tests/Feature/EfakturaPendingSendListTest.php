<?php

namespace Tests\Feature;

use App\Livewire\Efaktura\PendingSendList;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\CompanyType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Работниот список „е-Фактури што чекаат праќање": потврдени, непратени
 * излезни фактури од клиентите на канцеларијата.
 */
class EfakturaPendingSendListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'accountant', 'internal_client', 'freelancer_client'] as $role) {
            Role::findOrCreate($role);
        }
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function invoice(Company $company, array $attributes = []): SalesInvoice
    {
        $partner = Partner::factory()->for($company)->create(['name' => 'Купувач '.$company->id]);

        return SalesInvoice::factory()->for($company)->create($attributes + [
            'partner_id' => $partner->id,
            'status' => 'confirmed',
            'currency' => 'MKD',
            'invoice_date' => '2026-03-01',
            'efaktura_status' => 'not_sent',
        ]);
    }

    public function test_an_admin_sees_a_confirmed_unsent_invoice(): void
    {
        $company = Company::factory()->create(['name' => 'Алфа ДООЕЛ']);
        $this->invoice($company, ['invoice_number_formatted' => '2026/7']);

        Livewire::actingAs($this->userWithRole('admin'))->test(PendingSendList::class)
            ->assertSee('Алфа ДООЕЛ')
            ->assertSee('2026/7')
            ->assertSee('Купувач '.$company->id)
            ->assertSee('Не е пратена');
    }

    public function test_it_leaves_out_everything_that_is_not_waiting(): void
    {
        $company = Company::factory()->create();
        $waiting = $this->invoice($company);
        $this->invoice($company, ['status' => 'draft']);
        $this->invoice($company, ['efaktura_status' => 'sent', 'efaktura_sent_at' => now()]);
        $this->invoice($company, ['currency' => 'EUR']);
        $individual = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $this->invoice($individual);
        $noMaterial = Company::factory()->create(['uses_material' => false]);
        $this->invoice($noMaterial);

        Livewire::actingAs($this->userWithRole('admin'))->test(PendingSendList::class)
            ->assertViewHas('invoices', fn ($list) => $list->pluck('id')->all() === [$waiting->id]);
    }

    public function test_a_failed_attempt_stays_on_the_list_and_says_so(): void
    {
        $company = Company::factory()->create();
        $failed = $this->invoice($company, ['efaktura_status' => 'failed', 'efaktura_error' => 'грешка']);

        Livewire::actingAs($this->userWithRole('admin'))->test(PendingSendList::class)
            ->assertViewHas('invoices', fn ($list) => $list->pluck('id')->all() === [$failed->id])
            ->assertSee('Неуспешен обид');
    }

    public function test_the_lines_are_eager_loaded_so_the_totals_do_not_query_per_row(): void
    {
        $company = Company::factory()->create();
        $this->invoice($company);
        $this->invoice($company);
        $this->invoice($company);

        $linesQueries = 0;
        DB::listen(function ($query) use (&$linesQueries) {
            if (str_contains($query->sql, 'sales_invoice_lines')) {
                $linesQueries++;
            }
        });

        Livewire::actingAs($this->userWithRole('admin'))->test(PendingSendList::class)
            ->assertViewHas('invoices', fn ($list) => $list->count() === 3
                && $list->every(fn ($invoice) => $invoice->relationLoaded('lines')));

        // Еден заеднички упит за сите редови, не по еден за секоја фактура.
        $this->assertSame(1, $linesQueries);
    }

    public function test_the_oldest_invoice_comes_first(): void
    {
        $company = Company::factory()->create();
        $newer = $this->invoice($company, ['invoice_date' => '2026-05-01']);
        $older = $this->invoice($company, ['invoice_date' => '2026-01-01']);

        Livewire::actingAs($this->userWithRole('admin'))->test(PendingSendList::class)
            ->assertViewHas('invoices', fn ($list) => $list->pluck('id')->all() === [$older->id, $newer->id]);
    }

    public function test_an_accountant_sees_only_their_own_clients(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $mineInvoice = $this->invoice($mine);
        $this->invoice($theirs);
        $accountant = $this->userWithRole('accountant');
        $mine->accountants()->attach($accountant);

        Livewire::actingAs($accountant)->test(PendingSendList::class)
            ->assertViewHas('invoices', fn ($list) => $list->pluck('id')->all() === [$mineInvoice->id]);
    }

    public function test_it_says_what_kind_of_token_each_company_has(): void
    {
        $withToken = Company::factory()->create([
            'efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN,
            'efaktura_eujp_id' => 'EUJP-1',
            'efaktura_token_serial_number' => '1A2B3C',
        ]);
        $withoutToken = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN]);
        $officeToken = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_FIRM]);
        foreach ([$withToken, $withoutToken, $officeToken] as $company) {
            $this->invoice($company);
        }

        Livewire::actingAs($this->userWithRole('admin'))->test(PendingSendList::class)
            ->assertSee('Запишан')
            ->assertSee('Нема запишан токен')
            ->assertSee('Режим „канцеларија“ — праќањето не е поддржано');
    }

    public function test_clients_and_freelancers_are_refused(): void
    {
        $company = Company::factory()->create();
        $client = $this->userWithRole('internal_client', ['company_id' => $company->id]);
        $freelancer = $this->userWithRole('freelancer_client');

        $this->actingAs($client)->get(route('efaktura.pending'))->assertForbidden();
        $this->actingAs($freelancer)->get(route('efaktura.pending'))->assertForbidden();
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->get(route('efaktura.pending'))->assertRedirect(route('login'));
    }

    public function test_the_page_opens_for_an_admin_and_carries_the_sidebar_link(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get(route('efaktura.pending'))
            ->assertOk()
            ->assertSee('е-Фактури на чекање')
            ->assertSeeHtml(route('efaktura.pending'));
    }

    public function test_a_client_does_not_get_the_sidebar_link(): void
    {
        $company = Company::factory()->create();
        $client = $this->userWithRole('internal_client', ['company_id' => $company->id]);

        $this->actingAs($client)
            ->get(route('companies.dashboard', $company))
            ->assertOk()
            ->assertDontSee('е-Фактури на чекање');
    }
}
