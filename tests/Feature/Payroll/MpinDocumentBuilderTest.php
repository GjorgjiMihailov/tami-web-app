<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\Mpin\MpinDocumentBuilder;
use App\Support\Payroll\MpinObvrznik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MpinDocumentBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $employeeOverrides */
    private function confirmedRun(
        Company $company,
        array $employeeOverrides,
        float $gross,
        int $month,
    ): PayrollRun {
        // 21 работни денови по 8 часа = 168 — фондот што стои во вистинската
        // датотека за мај 2026. PayrollRunService::open() бара внесен фонд,
        // тоа не е дел од фабриката.
        PayrollMonthHours::firstOrCreate(['year' => 2026, 'month' => $month], ['hours' => 168]);

        $employee = Employee::factory()->for($company)->create([
            'exemption_code' => null,
            'terminated_on' => null,
            'prior_service_months' => 0,
            ...$employeeOverrides,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'amount' => $gross,
            'basis' => 'gross',
        ]);

        // confirmed_by е странски клуч кон users, па мора да е вистински корисник.
        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        return $service->confirm($service->open($company, 2026, $month), $user->id)->fresh();
    }

    public function test_an_obvrznik_110_filing_is_reproduced_byte_for_byte(): void
    {
        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'tax_id' => '4080000000000',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);

        $run = $this->confirmedRun($company, [
            'embg' => '0101990450006',
            'municipality_code' => '130',
            'health_area_code' => '4061',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'movement_code' => '1',
            'weekly_hours' => 40,
            'employed_on' => '2026-01-01',
        ], 38507, 5);

        $this->assertSame(
            file_get_contents(base_path('tests/Fixtures/mpin/obvrznik-110.xml')),
            MpinDocumentBuilder::build($run),
        );
    }

    /**
     * Регрес: минат труд (KIND_AMOUNT, шифра 037) некогаш беше исфрлен од
     * ниво 3 затоа што detailNode филтрираше само KIND_HOURS. Ниту еден од
     * двата еталони го фаќа тоа — ниту еден вработен нема стаж над една
     * година — па наместо трет byte-for-byte еталон (кој немаме од реална
     * поднесена датотека), се проверува инваријантата: збирот на BrutoIznos
     * на ниво 3 мора да е еднаков на BrutoIznosVkVrab на ниво 2, и редот со
     * шифра 037 мора да постои.
     */
    public function test_a_seniority_bonus_line_is_included_in_the_level_3_sum(): void
    {
        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'tax_id' => '4080000000000',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);

        // 4 месеци во фирмава (јан-мај) + 36 донесени = 40 месеци = 3 цели
        // години стаж, доволно минат труд да излезе > 0.
        $run = $this->confirmedRun($company, [
            'embg' => '0101990450006',
            'municipality_code' => '130',
            'health_area_code' => '4061',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'movement_code' => '1',
            'weekly_hours' => 40,
            'employed_on' => '2026-01-01',
            'prior_service_months' => 36,
        ], 38507, 5);

        $xml = new \SimpleXMLElement(MpinDocumentBuilder::build($run));
        $employeeNode = $xml->MpinCalculationSt[0];
        $details = $employeeNode->MpinCalculationStDetail;

        $codes = array_map('strval', $employeeNode->xpath('MpinCalculationStDetail/SifraTipRabotenCas'));
        $this->assertContains('037', $codes, 'Минат труд мора да е присутен како ред на ниво 3.');

        $detailSum = array_sum(array_map('intval', $employeeNode->xpath('MpinCalculationStDetail/BrutoIznos')));
        $this->assertSame(
            (int) $employeeNode->BrutoIznosVkVrab,
            $detailSum,
            'Збирот на BrutoIznos на ниво 3 мора да е еднаков на BrutoIznosVkVrab на ниво 2.'
        );

        // Редовните часови остануваат првиот ред (и го носат целиот стаж);
        // минат труд се додава по нив, не пред нив — тоа не се претпоставува,
        // туку се потврдува тука.
        $this->assertSame('001', (string) $details[0]->SifraTipRabotenCas);
        $this->assertSame((string) $employeeNode->DenoviStazVkVrab, (string) $details[0]->DenoviStaz);

        $seniorityIndex = array_search('037', $codes, true);
        $this->assertNotFalse($seniorityIndex);
        $this->assertSame('0', (string) $details[$seniorityIndex]->DenoviStaz);
        $this->assertSame('0', (string) $details[$seniorityIndex]->BrojCasovi);
    }

    public function test_the_file_name_matches_what_the_mpin_client_uses(): void
    {
        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);

        $run = PayrollRun::factory()->for($company)->create([
            'year' => 2026,
            'month' => 5,
        ]);

        $this->assertSame(
            'DESIGNIA DOOEL_2026_05_101.xml',
            MpinDocumentBuilder::fileName($run),
        );
    }
}
