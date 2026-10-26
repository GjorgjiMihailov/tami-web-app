<?php

namespace Tests\Feature\Ppf;

use App\Livewire\Payroll\PpfPaymentForm;
use App\Livewire\Payroll\PpfPaymentIndex;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\PpfCalculation;
use App\Models\PpfPayee;
use App\Models\PpfPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    private function calculation(string $date = '2026-10-15', array $attributes = []): PpfCalculation
    {
        return PpfCalculation::factory()->create(array_merge(['company_id' => $this->company->id, 'period_date' => $date], $attributes));
    }

    private function payment(?PpfCalculation $calculation = null, array $attributes = [], array $payee = []): PpfPayment
    {
        $calculation ??= $this->calculation();
        $person = PpfPayee::factory()->create(array_merge(['company_id' => $this->company->id], $payee));

        return PpfPayment::factory()->create(array_merge([
            'company_id' => $this->company->id, 'ppf_calculation_id' => $calculation->id,
            'ppf_payee_id' => $person->id, 'payment_date' => $calculation->period_date->toDateString(),
        ], $attributes));
    }

    private function index(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->admin)->test(PpfPaymentIndex::class, ['company' => $this->company]);
    }

    // --- список ---

    public function test_two_calculations_of_the_same_day_stay_separate(): void
    {
        $first = $this->calculation('2026-10-15');
        $second = $this->calculation('2026-10-15');
        $this->payment($first, ['gross_income' => 100000]);
        $this->payment($second, ['gross_income' => 50000]);

        $rows = $this->index()->viewData('calculations');

        $this->assertCount(2, $rows);
        $this->assertSame([2, 1], $rows->map(fn ($r) => $r['model']->number)->all());
        $this->assertSame([50000, 100000], $rows->pluck('total')->map(fn ($t) => (int) $t)->all());
    }

    public function test_a_calculation_collects_payments_of_one_or_several_people(): void
    {
        $calculation = $this->calculation();
        $this->payment($calculation);
        $this->payment($calculation);

        $this->assertCount(2, $this->index()->viewData('calculations')->first()['model']->payments);
    }

    public function test_the_month_filter_narrows_the_list(): void
    {
        $this->calculation('2026-10-15');
        $this->calculation('2026-09-02');

        $rows = $this->index()->set('month', '9')->viewData('calculations');

        $this->assertSame(['2026-09-02'], $rows->map(fn ($r) => $r['model']->period_date->toDateString())->all());
    }

    public function test_a_clean_calculation_offers_the_xml_to_the_office_only(): void
    {
        $calculation = $this->calculation();
        $this->payment($calculation);

        $this->actingAs($this->admin)->get(route('ppf-payments.index', $this->company))
            ->assertOk()
            ->assertSee('Преземи ППФ XML')
            ->assertSee(route('ppf-payments.export', [$this->company, $calculation]), false)
            ->assertSee('Нова пресметка')
            ->assertSee('Пресметка бр. 1');

        $this->actingAs($this->client())->get(route('ppf-payments.index', $this->company))
            ->assertOk()
            ->assertSee('Пресметка бр. 1')
            ->assertDontSee('Преземи ППФ XML')
            ->assertDontSee('Нова пресметка')
            ->assertDontSee('Додај исплата')
            ->assertDontSee('Избриши');
    }

    public function test_a_calculation_with_an_error_shows_it_and_no_download(): void
    {
        $this->payment(null, ['type_income_code' => 'T2', 'subtype_income_code' => null]);

        $this->actingAs($this->admin)->get(route('ppf-payments.index', $this->company))
            ->assertOk()
            ->assertSee('нема подвид')
            ->assertDontSee('Преземи ППФ XML');
    }

    public function test_an_empty_calculation_has_no_download(): void
    {
        $this->calculation();

        $this->actingAs($this->admin)->get(route('ppf-payments.index', $this->company))
            ->assertSee('нема ниту една исплата')
            ->assertDontSee('Преземи ППФ XML');
    }

    public function test_a_client_cannot_see_another_companys_list(): void
    {
        $this->actingAs($this->client(Company::factory()->create()))
            ->get(route('ppf-payments.index', $this->company))
            ->assertForbidden();
    }

    public function test_another_companys_calculations_are_not_listed(): void
    {
        $other = Company::factory()->create();
        PpfCalculation::factory()->create(['company_id' => $other->id]);

        $this->assertCount(0, $this->index()->viewData('calculations'));
    }

    // --- пресметки ---

    public function test_a_new_calculation_gets_the_next_number_of_the_year_and_opens_the_payment_form(): void
    {
        $this->calculation('2026-03-01');
        $this->calculation('2026-04-01');

        $this->index()->set('newDate', '2026-10-20')->call('createCalculation')->assertHasNoErrors();

        $created = PpfCalculation::where('company_id', $this->company->id)->latest('id')->first();
        $this->assertSame(3, $created->number);
        $this->assertSame(2026, $created->year);
        $this->assertSame('2026-10-20', $created->period_date->toDateString());
        $this->assertSame($this->admin->id, $created->created_by);
    }

    public function test_numbering_restarts_each_year(): void
    {
        $this->calculation('2026-03-01');

        $this->index()->set('newDate', '2025-12-30')->call('createCalculation');

        $this->assertSame(1, PpfCalculation::where('year', 2025)->firstOrFail()->number);
    }

    public function test_a_bad_date_for_a_new_calculation_is_refused(): void
    {
        $this->index()->set('newDate', '2017-12-31')->call('createCalculation')->assertHasErrors('newDate');
        $this->index()->set('newDate', 'нешто')->call('createCalculation')->assertHasErrors('newDate');

        $this->assertSame(0, PpfCalculation::count());
    }

    public function test_the_number_and_the_date_can_be_changed_and_the_export_stamp_is_cleared(): void
    {
        $calculation = $this->calculation('2026-10-15', ['exported_at' => now(), 'exported_by' => $this->admin->id]);

        $this->index()->call('startEditing', $calculation->id)
            ->assertSet('editNumber', '1')
            ->set('editNumber', '12')->set('editDate', '2026-10-20')
            ->call('updateCalculation')->assertHasNoErrors();

        $calculation->refresh();
        $this->assertSame(12, $calculation->number);
        $this->assertSame('2026-10-20', $calculation->period_date->toDateString());
        $this->assertNull($calculation->exported_at);
    }

    public function test_a_number_already_used_in_the_year_is_refused(): void
    {
        $this->calculation('2026-01-01');
        $second = $this->calculation('2026-02-01');

        $this->index()->call('startEditing', $second->id)->set('editNumber', '1')->call('updateCalculation')->assertHasErrors('editNumber');

        $this->assertSame(2, $second->fresh()->number);
    }

    public function test_a_bad_number_or_date_is_refused(): void
    {
        $calculation = $this->calculation();

        $this->index()->call('startEditing', $calculation->id)->set('editNumber', '0')->call('updateCalculation')->assertHasErrors('editNumber');
        $this->index()->call('startEditing', $calculation->id)->set('editNumber', '5')->set('editDate', '2026-02-31')->call('updateCalculation')->assertHasErrors('editDate');
    }

    public function test_deleting_a_calculation_deletes_its_payments(): void
    {
        $calculation = $this->calculation();
        $payment = $this->payment($calculation);
        $kept = $this->payment();

        $this->index()->call('deleteCalculation', $calculation->id);

        $this->assertNull($calculation->fresh());
        $this->assertNull($payment->fresh());
        $this->assertNotNull($kept->fresh());
    }

    public function test_a_client_cannot_change_calculations(): void
    {
        $calculation = $this->calculation();

        $this->index($this->client())->call('createCalculation')->assertForbidden();
        $this->index($this->client())->call('startEditing', $calculation->id)->assertForbidden();
        $this->index($this->client())->call('deleteCalculation', $calculation->id)->assertForbidden();
        $this->assertNotNull($calculation->fresh());
    }

    public function test_a_calculation_of_another_company_cannot_be_changed_or_deleted(): void
    {
        $foreign = PpfCalculation::factory()->create(['company_id' => Company::factory()->create()->id]);

        foreach (['deleteCalculation', 'startEditing'] as $action) {
            try {
                $this->index()->call($action, $foreign->id);
                $this->fail("$action на туѓа пресметка мора да падне");
            } catch (ModelNotFoundException) {
                // 404 во прелистувач
            }
        }

        $this->assertNotNull($foreign->fresh());
    }

    // --- бришење на исплата ---

    public function test_the_office_deletes_a_payment_but_a_client_cannot(): void
    {
        $payment = $this->payment();

        $this->index($this->client())->call('deletePayment', $payment->id)->assertForbidden();
        $this->assertNotNull($payment->fresh());

        $this->index()->call('deletePayment', $payment->id);
        $this->assertNull($payment->fresh());
    }

    public function test_deleting_a_payment_clears_the_export_stamp(): void
    {
        $calculation = $this->calculation('2026-10-15', ['exported_at' => now(), 'exported_by' => $this->admin->id]);
        $payment = $this->payment($calculation);

        $this->index()->call('deletePayment', $payment->id);

        $this->assertNull($calculation->fresh()->exported_at);
    }

    public function test_a_payment_of_another_company_is_not_deleted(): void
    {
        $foreign = PpfPayment::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->index()->call('deletePayment', $foreign->id);
        } finally {
            $this->assertNotNull($foreign->fresh());
        }
    }

    // --- форма ---

    private function fill(Testable $component, array $overrides = []): Testable
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

    private function form(?PpfCalculation $calculation = null): Testable
    {
        return Livewire::actingAs($this->admin)->test(PpfPaymentForm::class, [
            'company' => $this->company, 'calculation' => $calculation ?? $this->calculation(),
        ]);
    }

    public function test_the_form_starts_on_the_date_of_the_calculation(): void
    {
        $this->form($this->calculation('2026-08-20'))->assertSet('paymentDate', '2026-08-20');
    }

    public function test_saving_creates_the_payee_and_the_payment_on_the_calculation(): void
    {
        $calculation = $this->calculation();

        $this->fill($this->form($calculation))->call('save')->assertHasNoErrors()->assertRedirect(route('ppf-payments.index', $this->company));

        $payee = PpfPayee::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('3101980455019', $payee->embg);
        $this->assertSame('7862314908098', $payee->bank_account);

        $payment = PpfPayment::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($calculation->id, $payment->ppf_calculation_id);
        $this->assertSame($payee->id, $payment->ppf_payee_id);
        $this->assertSame('T1', $payment->type_income_code);
        $this->assertSame('1014', $payment->subtype_income_code);
        $this->assertSame(100000, $payment->gross_income);
        $this->assertSame($this->admin->id, $payment->created_by);
    }

    public function test_save_and_add_another_keeps_the_date_and_type_and_clears_the_person(): void
    {
        $calculation = $this->calculation();

        $component = $this->fill($this->form($calculation))->call('saveAndAddAnother')->assertHasNoErrors()
            ->assertSet('name', '')->assertSet('embg', '')->assertSet('gross', '')
            ->assertSet('typeCode', 'T1')->assertSet('subtypeCode', '1014')->assertSet('paymentDate', '2026-10-15')
            ->assertNoRedirect();
        $this->assertNotSame('', $component->get('savedNotice'));

        $this->fill($component, ['name' => 'Марко', 'embg' => '0101990450006', 'gross' => '5000'])->call('save');

        $this->assertSame(2, PpfPayment::where('ppf_calculation_id', $calculation->id)->count());
    }

    public function test_a_second_payment_to_the_same_person_reuses_the_payee(): void
    {
        $calculation = $this->calculation();
        $this->fill($this->form($calculation))->call('save');
        $this->fill($this->form($calculation), ['gross' => '5000', 'name' => 'Ана Марија'])->call('save');

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

    public function test_adding_a_payment_to_an_exported_calculation_clears_its_stamp(): void
    {
        $calculation = $this->calculation('2026-10-15', ['exported_at' => now(), 'exported_by' => $this->admin->id]);

        $this->fill($this->form($calculation))->call('save')->assertHasNoErrors();

        $this->assertNull($calculation->fresh()->exported_at);
    }

    public function test_editing_updates_the_payment_and_clears_the_export_stamp(): void
    {
        $calculation = $this->calculation('2026-10-15', ['exported_at' => now(), 'exported_by' => $this->admin->id]);
        $payment = $this->payment($calculation, ['gross_income' => 1000]);

        Livewire::actingAs($this->admin)->test(PpfPaymentForm::class, ['company' => $this->company, 'payment' => $payment])
            ->assertSet('gross', '1000')
            ->set('gross', '2500')
            ->call('save')->assertHasNoErrors();

        $payment->refresh();
        $this->assertSame(2500, $payment->gross_income);
        $this->assertSame($calculation->id, $payment->ppf_calculation_id);
        $this->assertNull($calculation->fresh()->exported_at);
        $this->assertSame(1, PpfPayment::count());
    }

    public function test_the_form_cannot_add_to_a_calculation_of_another_company(): void
    {
        $foreign = PpfCalculation::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($this->admin)->get(route('ppf-payments.create', [$this->company, $foreign]))->assertNotFound();
    }

    public function test_a_payment_of_another_company_cannot_be_edited(): void
    {
        $foreign = PpfPayment::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($this->admin)->get(route('ppf-payments.edit', [$this->company, $foreign]))->assertNotFound();
    }

    public function test_a_client_cannot_open_the_form(): void
    {
        $this->actingAs($this->client())->get(route('ppf-payments.create', [$this->company, $this->calculation()]))->assertForbidden();
    }

    // --- симнување ---

    public function test_the_office_downloads_the_xml_named_by_the_number_and_the_export_is_stamped(): void
    {
        $calculation = $this->calculation('2026-10-15', ['number' => 4]);
        $this->payment($calculation);

        $response = $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, $calculation]));

        $response->assertOk();
        $this->assertStringContainsString('application/xml', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('Akontativna presmetka br.4.xml', rawurldecode((string) $response->headers->get('content-disposition')));
        $this->assertStringContainsString('<ujp_ppf_acc_calc>', $response->getContent());
        $this->assertStringContainsString('<periodfrom>15.10.2026</periodfrom>', $response->getContent());
        $this->assertNotNull($calculation->fresh()->exported_at);
        $this->assertSame($this->admin->id, $calculation->fresh()->exported_by);
    }

    public function test_the_period_in_the_file_is_the_date_of_the_calculation_not_of_the_payment(): void
    {
        $calculation = $this->calculation('2026-05-13');
        $this->payment($calculation, ['payment_date' => '2026-05-06']);

        $content = $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, $calculation]))->assertOk()->getContent();

        $this->assertStringContainsString('<periodfrom>13.05.2026</periodfrom>', $content);
        $this->assertStringContainsString('<paymentdate>06.05.2026</paymentdate>', $content);
    }

    public function test_only_the_payments_of_that_calculation_are_in_the_file(): void
    {
        $first = $this->calculation('2026-10-15');
        $second = $this->calculation('2026-10-15');
        $this->payment($first, [], ['name' => 'Прв']);
        $this->payment($second, [], ['name' => 'Втор']);

        $content = $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, $first]))->getContent();

        $this->assertStringContainsString('Прв', $content);
        $this->assertStringNotContainsString('Втор', $content);
        $this->assertNull($second->fresh()->exported_at);
    }

    public function test_a_client_cannot_download(): void
    {
        $calculation = $this->calculation();
        $this->payment($calculation);

        $this->actingAs($this->client())->get(route('ppf-payments.export', [$this->company, $calculation]))->assertForbidden();
    }

    public function test_a_file_with_errors_is_not_downloaded_or_stamped(): void
    {
        $calculation = $this->calculation();
        $this->payment($calculation, ['gross_income' => 0]);

        $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, $calculation]))->assertRedirect();

        $this->assertNull($calculation->fresh()->exported_at);
    }

    public function test_another_companys_calculation_is_never_downloaded(): void
    {
        $other = Company::factory()->create();
        $foreign = PpfCalculation::factory()->create(['company_id' => $other->id]);

        $this->actingAs($this->admin)->get(route('ppf-payments.export', [$this->company, $foreign]))->assertNotFound();
    }

    public function test_the_menu_has_the_screen_under_payroll(): void
    {
        $this->actingAs($this->admin)->get(route('ppf-payments.index', $this->company))
            ->assertSee('Исплати на физички лица');
    }
}
