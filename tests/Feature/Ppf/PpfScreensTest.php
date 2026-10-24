<?php

namespace Tests\Feature\Ppf;

use App\Livewire\Payroll\PpfPaymentForm;
use App\Livewire\Payroll\PpfPaymentIndex;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\PpfPayee;
use App\Models\PpfPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PpfScreensTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'accountant', 'internal_client'] as $role) {
            Role::findOrCreate($role);
        }
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->company = Company::factory()->create(['tax_id' => '4030990239062', 'email' => 'office@example.com']);
        CompanyBankAccount::create(['company_id' => $this->company->id, 'bank_name' => 'Банка', 'account_number' => '100000000000002', 'position' => 1]);
    }

    private function client(?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => ($company ?? $this->company)->id]);
        $user->assignRole('internal_client');

        return $user;
    }

    private function payment(array $attributes = [], array $payee = []): PpfPayment
    {
        $person = PpfPayee::factory()->create(array_merge(['company_id' => $this->company->id], $payee));

        return PpfPayment::factory()->create(array_merge([
            'company_id' => $this->company->id, 'ppf_payee_id' => $person->id, 'payment_date' => '2026-10-15',
        ], $attributes));
    }

    // --- список ---

    public function test_the_list_groups_payments_by_date_with_totals(): void
    {
        $this->payment(['payment_date' => '2026-10-15', 'gross_income' => 100000]);
        $this->payment(['payment_date' => '2026-10-15', 'gross_income' => 50000]);
        $this->payment(['payment_date' => '2026-09-02', 'gross_income' => 7000]);

        $groups = Livewire::actingAs($this->admin)
            ->test(PpfPaymentIndex::class, ['company' => $this->company])
            ->viewData('groups');

        $this->assertSame(['2026-10-15', '2026-09-02'], $groups->keys()->all());
        $this->assertSame(150000, (int) $groups['2026-10-15']['total']);
        $this->assertCount(2, $groups['2026-10-15']['payments']);
    }

    public function test_the_month_filter_narrows_the_list(): void
    {
        $this->payment(['payment_date' => '2026-10-15']);
        $this->payment(['payment_date' => '2026-09-02']);

        $groups = Livewire::actingAs($this->admin)
            ->test(PpfPaymentIndex::class, ['company' => $this->company])
            ->set('month', '9')
            ->viewData('groups');

        $this->assertSame(['2026-09-02'], $groups->keys()->all());
    }

    public function test_a_clean_group_offers_the_xml_to_the_office_only(): void
    {
        $this->payment();

        $this->actingAs($this->admin)->get(route('ppf-payments.index', $this->company))
            ->assertOk()
            ->assertSee('Преземи ППФ XML')
            ->assertSee(route('ppf-payments.export', [$this->company, '2026-10-15']), false)
            ->assertSee('Нова исплата');

        $this->actingAs($this->client())->get(route('ppf-payments.index', $this->company))
            ->assertOk()
            ->assertDontSee('Преземи ППФ XML')
            ->assertDontSee('Нова исплата')
            ->assertDontSee('Избриши');
    }

    public function test_a_group_with_an_error_shows_it_and_no_download(): void
    {
        $this->payment(['type_income_code' => 'T2', 'subtype_income_code' => null]);

        $this->actingAs($this->admin)->get(route('ppf-payments.index', $this->company))
            ->assertOk()
            ->assertSee('нема подвид')
            ->assertDontSee('Преземи ППФ XML');
    }

    public function test_a_client_cannot_see_another_companys_list(): void
    {
        $this->actingAs($this->client(Company::factory()->create()))
            ->get(route('ppf-payments.index', $this->company))
            ->assertForbidden();
    }

    public function test_the_menu_has_the_screen_under_payroll(): void
    {
        $this->actingAs($this->admin)->get(route('ppf-payments.index', $this->company))
            ->assertSee('Исплати на физички лица');
    }

    // --- бришење ---

    public function test_the_office_deletes_a_payment_but_a_client_cannot(): void
    {
        $payment = $this->payment();

        Livewire::actingAs($this->client())->test(PpfPaymentIndex::class, ['company' => $this->company])
            ->call('deletePayment', $payment->id)
            ->assertForbidden();
        $this->assertNotNull($payment->fresh());

        Livewire::actingAs($this->admin)->test(PpfPaymentIndex::class, ['company' => $this->company])
            ->call('deletePayment', $payment->id);
        $this->assertNull($payment->fresh());
    }

    public function test_a_payment_of_another_company_is_not_deleted(): void
    {
        $other = Company::factory()->create();
        $foreign = PpfPayment::factory()->create(['company_id' => $other->id]);

        Livewire::actingAs($this->admin)->test(PpfPaymentIndex::class, ['company' => $this->company])
            ->call('deletePayment', $foreign->id);

        $this->assertNotNull($foreign->fresh());
    }

    // --- форма ---

    private function fill($component, array $overrides = [])
    {
        $values = array_merge([
            'name' => 'Ана', 'surname' => 'Петровска', 'embg' => '3101980455019', 'municipalityCode' => '179',
            'bankAccount' => '7862314908098', 'paymentDate' => '2026-10-15', 'typeCode' => 'T1', 'subtypeCode' => '1014',
            'gross' => '100000', 'paymentTypeCode' => '00001',
        ], $overrides);

        foreach ($values as $key => $value) {
            $component->set($key, $value);
        }

        return $component;
    }

    private function form(): Testable
    {
        return Livewire::actingAs($this->admin)->test(PpfPaymentForm::class, ['company' => $this->company]);
    }

    public function test_saving_creates_the_payee_and_the_payment(): void
    {
        $this->fill($this->form())->call('save')->assertHasNoErrors()->assertRedirect(route('ppf-payments.index', $this->company));

        $payee = PpfPayee::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('3101980455019', $payee->embg);
        $this->assertSame('7862314908098', $payee->bank_account);

        $payment = PpfPayment::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($payee->id, $payment->ppf_payee_id);
        $this->assertSame('T1', $payment->type_income_code);
        $this->assertSame('1014', $payment->subtype_income_code);
        $this->assertSame(100000, $payment->gross_income);
        $this->assertSame('2026-10-15', $payment->payment_date->toDateString());
        $this->assertSame($this->admin->id, $payment->created_by);
    }

    public function test_a_second_payment_to_the_same_person_reuses_the_payee(): void
    {
        $this->fill($this->form())->call('save');
        $this->fill($this->form(), ['gross' => '5000', 'name' => 'Ана Марија'])->call('save');

        $this->assertSame(1, PpfPayee::where('company_id', $this->company->id)->count());
        $this->assertSame(2, PpfPayment::count());
        $this->assertSame('Ана Марија', PpfPayee::firstOrFail()->name);
    }

    public function test_choosing_an_existing_payee_fills_the_person(): void
    {
        $payee = PpfPayee::factory()->create(['company_id' => $this->company->id, 'name' => 'Марко', 'municipality_code' => '103']);

        $this->form()->set('payeeId', (string) $payee->id)
            ->assertSet('name', 'Марко')
            ->assertSet('embg', $payee->embg)
            ->assertSet('municipalityCode', '103');
    }

    public function test_a_payee_of_another_company_cannot_be_chosen(): void
    {
        $foreign = PpfPayee::factory()->create(['name' => 'Туѓ']);

        $this->form()->set('payeeId', (string) $foreign->id)
            ->assertSet('name', '')
            ->assertSet('payeeId', '');
    }

    public function test_the_form_validates_the_fields(): void
    {
        $this->fill($this->form(), ['embg' => '1234567890123'])->call('save')->assertHasErrors('embg');
        $this->fill($this->form(), ['municipalityCode' => '999'])->call('save')->assertHasErrors('municipalityCode');
        $this->fill($this->form(), ['gross' => '100.50'])->call('save')->assertHasErrors('gross');
        $this->fill($this->form(), ['gross' => '0'])->call('save')->assertHasErrors('gross');
        $this->fill($this->form(), ['subtypeCode' => ''])->call('save')->assertHasErrors('subtypeCode');
        $this->fill($this->form(), ['subtypeCode' => '3004'])->call('save')->assertHasErrors('subtypeCode');
        $this->fill($this->form(), ['bankAccount' => ''])->call('save')->assertHasErrors('bankAccount');
        $this->fill($this->form(), ['paymentDate' => '2017-12-31'])->call('save')->assertHasErrors('paymentDate');
        $this->assertSame(0, PpfPayment::count());
    }

    public function test_another_way_of_payment_does_not_need_the_account(): void
    {
        $this->fill($this->form(), ['paymentTypeCode' => '00002', 'bankAccount' => ''])->call('save')->assertHasNoErrors();

        $this->assertSame(1, PpfPayment::count());
    }

    public function test_a_type_without_subtypes_is_saved_without_one(): void
    {
        $this->fill($this->form(), ['typeCode' => 'T2', 'subtypeCode' => ''])->call('save')->assertHasNoErrors();

        $this->assertNull(PpfPayment::firstOrFail()->subtype_income_code);
    }

    public function test_changing_the_date_resets_a_type_that_no_longer_applies(): void
    {
        $this->form()->set('paymentDate', '2018-06-15')->assertSet('typeCode', '')->assertSet('subtypeCode', '');
    }

    public function test_changing_the_type_clears_the_subtype(): void
    {
        $this->form()->set('typeCode', 'T3')->assertSet('subtypeCode', '');
    }

    public function test_the_optional_fields_are_saved(): void
    {
        $this->fill($this->form(), ['advance' => '10', 'vatAmount' => '20', 'grossFree' => '30', 'decisionNumber' => 'РЕШ-5', 'decisionDate' => '2026-09-01', 'comment' => 'белешка'])
            ->call('save')->assertHasNoErrors();

        $payment = PpfPayment::firstOrFail();
        $this->assertSame([10, 20, 30], [$payment->advance, $payment->vat_amount, $payment->gross_free]);
        $this->assertSame('РЕШ-5', $payment->decision_number);
        $this->assertSame('2026-09-01', $payment->decision_date->toDateString());
    }

    public function test_editing_updates_the_payment_and_clears_the_export_stamp(): void
    {
        $payment = $this->payment(['gross_income' => 1000, 'exported_at' => now(), 'exported_by' => $this->admin->id]);

        Livewire::actingAs($this->admin)->test(PpfPaymentForm::class, ['company' => $this->company, 'payment' => $payment])
            ->assertSet('gross', '1000')
            ->set('gross', '2500')
            ->call('save')->assertHasNoErrors();

        $payment->refresh();
        $this->assertSame(2500, $payment->gross_income);
        $this->assertNull($payment->exported_at);
        $this->assertSame(1, PpfPayment::count());
    }

    public function test_a_payment_of_another_company_cannot_be_edited(): void
    {
        $foreign = PpfPayment::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($this->admin)->get(route('ppf-payments.edit', [$this->company, $foreign]))->assertNotFound();
    }

    public function test_a_client_cannot_open_the_form(): void
    {
        $this->actingAs($this->client())->get(route('ppf-payments.create', $this->company))->assertForbidden();
    }

    // --- симнување ---

    public function test_the_office_downloads_the_xml_and_the_export_is_stamped(): void
    {
        $payment = $this->payment();

        $response = $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, '2026-10-15']));

        $response->assertOk();
        $this->assertStringContainsString('application/xml', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('Akontativna presmetka 15.10.2026.xml', rawurldecode((string) $response->headers->get('content-disposition')));
        $this->assertStringContainsString('<ujp_ppf_acc_calc>', $response->getContent());
        $this->assertStringContainsString('<periodfrom>15.10.2026</periodfrom>', $response->getContent());
        $this->assertNotNull($payment->fresh()->exported_at);
        $this->assertSame($this->admin->id, $payment->fresh()->exported_by);
    }

    public function test_only_the_payments_of_that_date_are_in_the_file(): void
    {
        $this->payment(['payment_date' => '2026-10-15'], ['name' => 'Прв']);
        $other = $this->payment(['payment_date' => '2026-10-16'], ['name' => 'Втор']);

        $content = $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, '2026-10-15']))->getContent();

        $this->assertStringContainsString('Прв', $content);
        $this->assertStringNotContainsString('Втор', $content);
        $this->assertNull($other->fresh()->exported_at);
    }

    public function test_a_client_cannot_download(): void
    {
        $this->payment();

        $this->actingAs($this->client())->get(route('ppf-payments.export', [$this->company, '2026-10-15']))->assertForbidden();
    }

    public function test_a_file_with_errors_is_not_downloaded_or_stamped(): void
    {
        $payment = $this->payment(['gross_income' => 0]);

        $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, '2026-10-15']))->assertRedirect();

        $this->assertNull($payment->fresh()->exported_at);
    }

    public function test_a_date_without_payments_or_a_bad_date_is_not_found(): void
    {
        $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, '2026-10-15']))->assertNotFound();
        $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, '2026-02-31']))->assertNotFound();
    }

    public function test_another_companys_payments_are_never_in_the_file(): void
    {
        $other = Company::factory()->create();
        PpfPayment::factory()->create(['company_id' => $other->id, 'payment_date' => '2026-10-15']);

        $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, '2026-10-15']))->assertNotFound();
    }

    public function test_the_download_can_carry_a_later_period_date(): void
    {
        $this->payment(['payment_date' => '2026-05-06']);

        $content = $this->actingAs($this->admin)
            ->get(route('ppf-payments.export', [$this->company, '2026-05-06']).'?period=2026-05-13')
            ->assertOk()->getContent();

        $this->assertStringContainsString('<periodfrom>13.05.2026</periodfrom>', $content);
        $this->assertStringContainsString('<paymentdate>06.05.2026</paymentdate>', $content);
    }

    public function test_a_malformed_period_is_not_found(): void
    {
        $this->payment(['payment_date' => '2026-05-06']);

        $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, '2026-05-06']).'?period=13.05.2026')->assertNotFound();
        $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, '2026-05-06']).'?period=2026-02-31')->assertNotFound();
    }

    public function test_the_list_offers_the_payment_date_as_the_period_and_follows_a_change(): void
    {
        $this->payment(['payment_date' => '2026-05-06']);

        $component = Livewire::actingAs($this->admin)->test(PpfPaymentIndex::class, ['company' => $this->company]);
        $this->assertSame('2026-05-06', $component->viewData('groups')['2026-05-06']['period']);

        $component->set('periods.2026-05-06', '2026-05-13');
        $this->assertSame('2026-05-13', $component->viewData('groups')['2026-05-06']['period']);
        $component->assertSee('?period=2026-05-13', false);

        $component->set('periods.2026-05-06', 'нешто');
        $this->assertSame('2026-05-06', $component->viewData('groups')['2026-05-06']['period']);
    }
}
