<?php

namespace Tests\Feature\Ppf;

use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\PpfCalculation;
use App\Models\PpfPayee;
use App\Models\PpfPayment;
use App\Support\Ppf\PpfCodes;
use App\Support\Ppf\PpfDocumentBuilder;
use App\Support\Ppf\PpfValidator;
use Carbon\Carbon;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PpfDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['name' => 'Тест Фирма ДООЕЛ', 'tax_id' => '4030990239062', 'email' => 'office@example.com']);
        CompanyBankAccount::create(['company_id' => $this->company->id, 'bank_name' => 'Банка', 'account_number' => '100-0000000000-02', 'position' => 1]);
    }

    private function payee(array $attributes = []): PpfPayee
    {
        return PpfPayee::factory()->create(array_merge([
            'company_id' => $this->company->id, 'name' => 'Ана', 'surname' => 'Петровска',
            'municipality_code' => '179', 'bank_account' => '7862314908098',
        ], $attributes));
    }

    private function payment(?PpfPayee $payee = null, array $attributes = []): PpfPayment
    {
        $payee ??= $this->payee();

        // Основно секоја исплата е на своја пресметка (на денот на исплатата); $attributes може да даде заедничка.
        return PpfPayment::factory()->create(array_merge([
            'company_id' => $this->company->id, 'ppf_payee_id' => $payee->id,
        ], $attributes))->load('payee', 'calculation');
    }

    private function build(PpfPayment ...$payments): string
    {
        return PpfDocumentBuilder::build($this->company->fresh('bankAccounts'), collect($payments), $payments[0]->calculation->period_date);
    }

    // --- облик како вистинските фајлови ---

    public function test_the_file_has_the_exact_shape_of_a_real_filing(): void
    {
        $xml = $this->build($this->payment($this->payee(['embg' => '3101980455019']), ['gross_income' => 100000, 'payment_date' => '2026-10-01']));

        $expected = implode("\r\n", [
            '<?xml version="1.0" standalone="yes"?>',
            '<ujp_ppf_acc_calc>',
            "\t<ppfTaxpayer>",
            "\t\t<edb>4030990239062</edb>",
            "\t</ppfTaxpayer>",
            "\t<name>Тест Фирма ДООЕЛ</name>",
            "\t<pca_bank_account>100000000000002</pca_bank_account>",
            "\t<periodfrom>01.10.2026</periodfrom>",
            "\t<periodto>01.10.2026</periodto>",
            "\t<contact></contact>",
            "\t<mail>office@example.com</mail>",
            "\t<calculation_detail>",
            "\t\t<row_id>1</row_id>",
            "\t\t<embg>3101980455019</embg>",
            "\t\t<name>Ана</name>",
            "\t\t<surname>Петровска</surname>",
            "\t\t<parMunicipality>",
            "\t\t\t<municipality_code>179</municipality_code>",
            "\t\t</parMunicipality>",
            "\t\t<type_income>",
            "\t\t\t<code>T1</code>",
            "\t\t</type_income>",
            "\t\t<subtype_income>",
            "\t\t\t<code>1014</code>",
            "\t\t</subtype_income>",
            "\t\t<grossIncome>100000</grossIncome>",
            "\t\t<type_payment>",
            "\t\t\t<ptpCode>00001</ptpCode>",
            "\t\t</type_payment>",
            "\t\t<bankAccount>7862314908098</bankAccount>",
            "\t\t<paymentdate>01.10.2026</paymentdate>",
            "\t</calculation_detail>",
            '</ujp_ppf_acc_calc>',
            '',
        ]);

        $this->assertSame($expected, $xml);
    }

    public function test_the_company_account_is_empty_when_the_profile_has_none(): void
    {
        CompanyBankAccount::where('company_id', $this->company->id)->delete();

        $this->assertStringContainsString("\t<pca_bank_account></pca_bank_account>\r\n", $this->build($this->payment()));
    }

    public function test_several_payments_get_running_row_ids(): void
    {
        $calculation = PpfCalculation::factory()->create(['company_id' => $this->company->id, 'period_date' => '2026-10-01']);
        $a = $this->payment($this->payee(), ['payment_date' => '2026-10-01', 'ppf_calculation_id' => $calculation->id]);
        $b = $this->payment($this->payee(['name' => 'Марко']), ['payment_date' => '2026-10-01', 'gross_income' => 5000, 'ppf_calculation_id' => $calculation->id]);

        $xml = $this->build($b, $a);

        $this->assertSame(1, preg_match_all('/<row_id>1<\/row_id>/', $xml));
        $this->assertSame(1, preg_match_all('/<row_id>2<\/row_id>/', $xml));
        $this->assertLessThan(strpos($xml, 'Марко'), strpos($xml, 'Ана'), 'редоследот е по id на исплатата, не по редот на повикот');
    }

    public function test_special_characters_are_escaped(): void
    {
        $xml = $this->build($this->payment($this->payee(['surname' => 'Петровски & <Син>'])));

        $this->assertStringContainsString('<surname>Петровски &amp; &lt;Син&gt;</surname>', $xml);
        $this->assertNotFalse((new DOMDocument)->loadXML($xml));
    }

    public function test_optional_fields_appear_only_when_filled(): void
    {
        $plain = $this->build($this->payment());
        foreach (['avans', 'ddv_iznos', 'grossFree', 'brojResenieDo', 'datumResenieDo', 'commentDoc'] as $tag) {
            $this->assertStringNotContainsString("<$tag>", $plain);
        }

        $full = $this->build($this->payment(null, [
            'advance' => 10, 'vat_amount' => 20, 'gross_free' => 30, 'decision_number' => 'РЕШ-5',
            'decision_date' => '2026-09-01', 'comment' => 'белешка',
        ]));
        $this->assertStringContainsString('<avans>10</avans>', $full);
        $this->assertStringContainsString('<ddv_iznos>20</ddv_iznos>', $full);
        $this->assertStringContainsString('<grossFree>30</grossFree>', $full);
        $this->assertStringContainsString('<brojResenieDo>РЕШ-5</brojResenieDo>', $full);
        $this->assertStringContainsString('<datumResenieDo>01.09.2026</datumResenieDo>', $full);
        $this->assertStringContainsString('<commentDoc>белешка</commentDoc>', $full);
    }

    public function test_a_payment_in_another_way_without_a_payee_account_leaves_the_account_out(): void
    {
        $xml = $this->build($this->payment($this->payee(['bank_account' => null]), ['payment_type_code' => '00002']));

        $this->assertStringContainsString('<ptpCode>00002</ptpCode>', $xml);
        $this->assertStringNotContainsString('<bankAccount>', $xml);
    }

    public function test_the_file_name_is_by_the_number_of_the_calculation(): void
    {
        $calculation = PpfCalculation::factory()->create(['company_id' => $this->company->id, 'number' => 7]);

        $this->assertSame('Akontativna presmetka br.7.xml', PpfDocumentBuilder::fileName($calculation));
    }

    // --- XSD ---

    public function test_the_built_file_passes_the_ujp_schema_for_every_valid_pair_of_codes(): void
    {
        foreach (PpfCodes::subtypes() as $subtype) {
            $legacy = PpfCodes::isLegacy($subtype['type']);
            $payment = $this->payment($this->payee(['embg' => '3101980455019']), [
                'type_income_code' => $subtype['type'], 'subtype_income_code' => $subtype['code'],
                'payment_date' => $legacy ? '2018-06-15' : '2026-06-15',
            ]);

            $result = PpfValidator::check($this->company, $payment->calculation);

            $this->assertSame([], $result->errors, "{$subtype['type']}/{$subtype['code']}");
            $payment->payee->delete();
        }
    }

    // --- проверки ---

    private function errors(PpfPayment ...$payments): array
    {
        return PpfValidator::check($this->company->fresh(), $payments[0]->calculation->fresh())->errors;
    }

    private function assertError(string $needle, array $errors): void
    {
        $this->assertTrue(
            collect($errors)->contains(fn (string $e) => str_contains($e, $needle)),
            "Нема грешка со „{$needle}“ во: ".implode(' | ', $errors),
        );
    }

    public function test_a_clean_payment_has_no_errors(): void
    {
        $this->assertSame([], $this->errors($this->payment()));
    }

    public function test_an_invalid_embg_is_refused(): void
    {
        $this->assertError('ЕМБГ', $this->errors($this->payment($this->payee(['embg' => '1234567890123']))));
    }

    public function test_a_municipality_outside_the_list_or_the_placeholder_is_refused(): void
    {
        $this->assertError('општината', $this->errors($this->payment($this->payee(['municipality_code' => '000']))));
        $this->assertError('општината', $this->errors($this->payment($this->payee(['municipality_code' => '999']))));
    }

    public function test_a_subtype_of_another_type_is_refused(): void
    {
        $this->assertError('не припаѓа', $this->errors($this->payment(null, ['type_income_code' => 'T3', 'subtype_income_code' => '1014'])));
    }

    public function test_a_type_without_subtypes_in_the_list_blocks_the_export_with_the_reason(): void
    {
        $this->assertError('нема подвид', $this->errors($this->payment(null, ['type_income_code' => 'T2', 'subtype_income_code' => null])));
    }

    public function test_a_missing_subtype_is_refused(): void
    {
        $this->assertError('подвид', $this->errors($this->payment(null, ['subtype_income_code' => null])));
    }

    public function test_the_old_codes_are_refused_for_a_payment_in_2026(): void
    {
        $this->assertError('не важи', $this->errors($this->payment(null, ['type_income_code' => '7', 'subtype_income_code' => '71'])));
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->assertError('не постои', $this->errors($this->payment(null, ['type_income_code' => 'X9'])));
    }

    public function test_a_zero_gross_is_refused(): void
    {
        $this->assertError('поголем од нула', $this->errors($this->payment(null, ['gross_income' => 0])));
    }

    public function test_a_payment_before_2018_is_refused(): void
    {
        $this->assertError('2018', $this->errors($this->payment(null, ['payment_date' => '2017-12-31', 'type_income_code' => '7', 'subtype_income_code' => '71'])));
    }

    public function test_a_payment_to_an_account_needs_the_payee_account_but_another_way_does_not(): void
    {
        $this->assertError('сметката на примателот', $this->errors($this->payment($this->payee(['bank_account' => null]))));
        $this->assertSame([], $this->errors($this->payment($this->payee(['bank_account' => null]), ['payment_type_code' => '00002'])));
    }

    public function test_the_company_needs_an_edb_and_an_email(): void
    {
        $payment = $this->payment();
        $this->company->update(['tax_id' => null, 'email' => null]);

        $errors = $this->errors($payment);

        $this->assertError('ЕДБ', $errors);
        $this->assertError('е-пошта', $errors);
    }

    public function test_payments_of_different_dates_can_be_in_one_calculation(): void
    {
        $calculation = PpfCalculation::factory()->create(['company_id' => $this->company->id, 'period_date' => '2026-10-05']);
        $this->payment(null, ['payment_date' => '2026-10-01', 'ppf_calculation_id' => $calculation->id]);
        $this->payment(null, ['payment_date' => '2026-10-02', 'ppf_calculation_id' => $calculation->id]);

        $this->assertSame([], PpfValidator::check($this->company->fresh(), $calculation->fresh())->errors);
    }

    public function test_a_payee_of_another_company_is_refused(): void
    {
        $foreign = PpfPayee::factory()->create();
        $payment = PpfPayment::factory()->create(['company_id' => $this->company->id, 'ppf_payee_id' => $foreign->id])->load('payee', 'calculation');

        $this->assertError('не припаѓаат', $this->errors($payment));
    }

    public function test_a_company_without_an_account_gets_only_a_warning(): void
    {
        CompanyBankAccount::where('company_id', $this->company->id)->delete();

        $result = PpfValidator::check($this->company->fresh(), $this->payment()->calculation);

        $this->assertTrue($result->passes());
        $this->assertNotEmpty($result->warnings);
    }

    public function test_no_payments_is_an_error(): void
    {
        $empty = PpfCalculation::factory()->create(['company_id' => $this->company->id]);

        $this->assertError('нема ниту една исплата', PpfValidator::check($this->company, $empty)->errors);
    }

    public function test_the_period_can_be_a_later_date_than_the_payment(): void
    {
        $payment = $this->payment(null, ['payment_date' => '2026-05-06']);

        $xml = PpfDocumentBuilder::build($this->company->fresh('bankAccounts'), collect([$payment]), Carbon::parse('2026-05-13'));

        $this->assertStringContainsString('<periodfrom>13.05.2026</periodfrom>', $xml);
        $this->assertStringContainsString('<periodto>13.05.2026</periodto>', $xml);
        $this->assertStringContainsString('<paymentdate>06.05.2026</paymentdate>', $xml);
    }

    public function test_a_period_before_the_payment_is_only_a_warning_and_before_2018_an_error(): void
    {
        $payment = $this->payment(null, ['payment_date' => '2026-05-06']);

        $payment->calculation->update(['period_date' => '2026-05-01']);
        $early = PpfValidator::check($this->company->fresh(), $payment->calculation->fresh());
        $this->assertTrue($early->passes());
        $this->assertNotEmpty($early->warnings);

        $payment->calculation->update(['period_date' => '2017-12-31']);
        $ancient = PpfValidator::check($this->company->fresh(), $payment->calculation->fresh());
        $this->assertError('01.01.2018', $ancient->errors);
    }
}
