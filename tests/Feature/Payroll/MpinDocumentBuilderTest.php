<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\LineType;
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
        // Фондот што стои во вистинските датотеки: 168 за мај 2026 (21
        // работен ден по 8 часа) и 176 за јануари 2026 (22 работни дена по 8
        // часа; празниците не се одземаат). PayrollRunService::open() бара
        // внесен фонд, тоа не е дел од фабриката.
        $hoursByMonth = [1 => 176, 5 => 168];
        PayrollMonthHours::firstOrCreate(
            ['year' => 2026, 'month' => $month],
            ['hours' => $hoursByMonth[$month]],
        );

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

    /**
     * Го гради истиот XML што еталонот за обврзник 110 го проверува. Изваден
     * во помошник за да не се повторува пополнувањето во форматните тестови,
     * кои намерно го проверуваат ПРОИЗВЕДЕНИОТ XML, не еталонот — еталон што
     * сам себе се проверува не докажува ништо.
     */
    private function designiaXml(): string
    {
        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'tax_id' => '4080000000000',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);

        return MpinDocumentBuilder::build($this->confirmedRun($company, [
            'embg' => '0101990450006',
            'municipality_code' => '130',
            'health_area_code' => '4061',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'movement_code' => '1',
            'weekly_hours' => 40,
            'employed_on' => '2026-01-01',
        ], 38507, 5));
    }

    public function test_an_obvrznik_110_filing_is_reproduced_byte_for_byte(): void
    {
        $this->assertSame(
            file_get_contents(base_path('tests/Fixtures/mpin/obvrznik-110.xml')),
            $this->designiaXml(),
        );
    }

    public function test_an_obvrznik_111_part_time_filing_is_reproduced_byte_for_byte(): void
    {
        $company = Company::factory()->create([
            'name' => 'ADVOKAT STEFAN KOTEV',
            'tax_id' => '4090000000000',
            'mpin_obvrznik_code' => MpinObvrznik::SELF_EMPLOYED,
        ]);

        $run = $this->confirmedRun($company, [
            'embg' => '1503880410003',
            'municipality_code' => '182',
            'health_area_code' => '4061',
            'bank_account' => '300000000000001',
            'insurance_type_code' => '0047',
            'movement_code' => '1',
            'exemption_code' => '001',
            'weekly_hours' => 20,
            // 2020-01-01 не е извадено од датотеката — DatumPocetok во МПИН
            // ја носи само покриеноста на месецот, не датумот на вработување,
            // па филингот воопшто не открива колку долго лицето работи (кое
            // да е employed_on пред почетокот на месецот, DatumPocetok секогаш
            // излегува 1.01.2026, види ја 110-датотеката со employed_on
            // 2026-01-01 против мај-датумот 1.05.2026). Датумот е избран
            // нарочно подалеку наназад за да остане > 0 години стаж и со тоа
            // да го „опфати" MpinObvrznik::paysSeniorityBonus() — ова е
            // единственото end-to-end покривање на тој гејт. Не го порамнувај
            // со 110-тестот (2026-01-01): тоа тивко би го изгасило гејтот
            // додека еталонот сепак поминува.
            'employed_on' => '2020-01-01',
        ], 34571, 1);

        $this->assertSame(
            file_get_contents(base_path('tests/Fixtures/mpin/obvrznik-111.xml')),
            MpinDocumentBuilder::build($run),
        );
    }

    public function test_an_empty_element_is_not_self_closing(): void
    {
        $xml = $this->designiaXml();

        $this->assertStringContainsString('<Zabeleska></Zabeleska>', $xml);
        $this->assertStringContainsString('<NadlezenOrganEdb></NadlezenOrganEdb>', $xml);
        $this->assertStringNotContainsString('/>', $xml);
    }

    public function test_the_day_has_no_leading_zero_but_the_month_does(): void
    {
        $xml = $this->designiaXml();

        $this->assertStringContainsString('<DatumPocetok>1.05.2026</DatumPocetok>', $xml);
        $this->assertStringNotContainsString('<DatumPocetok>01.05.2026</DatumPocetok>', $xml);
    }

    public function test_no_amount_carries_a_decimal(): void
    {
        $this->assertSame(
            0,
            preg_match('/<[A-Za-z]*Iznos[A-Za-z]*>-?[0-9]+\.[0-9]+</', $this->designiaXml()),
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

    /**
     * Потврдена пресметка чии заокружувања намерно се разидуваат: фонд 160
     * часа, договорено бруто 34 571, 8 часа боледување на 70% и една завршена
     * година стаж. Линиите излегуваат 32 842,45 + 1 209,99 + 164,21 = 34 216,65
     * — заокружениот збир е 34 217, а збирот на заокружените линии 34 216.
     */
    private function roundingClashRun(): PayrollRun
    {
        PayrollMonthHours::firstOrCreate(
            ['year' => 2026, 'month' => 4],
            ['hours' => 160],
        );

        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'tax_id' => '4080000000000',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);

        $employee = Employee::factory()->for($company)->create([
            'embg' => '0101990450006',
            'municipality_code' => '130',
            'health_area_code' => '4061',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'movement_code' => '1',
            'exemption_code' => null,
            'weekly_hours' => 40,
            'employed_on' => '2026-01-01',
            'terminated_on' => null,
            // 4 месеци во фирмава + 12 донесени = 16 месеци = точно една
            // завршена година, значи минат труд > 0.
            'prior_service_months' => 12,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2026-01-01',
            'amount' => 34571,
            'basis' => 'gross',
        ]);

        $service = app(PayrollRunService::class);
        $run = $service->open($company, 2026, 4);
        $row = $run->employees->first();

        $row->lines->firstWhere('code', '001')->update(['hours' => 152]);

        PayrollRunLine::create([
            'payroll_run_employee_id' => $row->id,
            'kind' => PayrollRunLine::KIND_HOURS,
            'code' => '125',
            'description' => LineType::label('125'),
            'hours' => 8,
            'percent' => 70,
            'amount' => 0,
            'borne_by' => PayrollRunLine::BORNE_EMPLOYER,
            'is_automatic' => false,
        ]);

        $user = User::factory()->create();

        return $service->confirm($service->recalculate($run->fresh()), $user->id)->fresh();
    }

    /**
     * Ниво 2 е заокружување на збир, ниво 3 беше збир на заокружувања — двете
     * се разидуваат за денар штом работникот има повеќе од една не-задршкина
     * ставка. УЈП ги споредува, па филингот излегуваше внатрешно противречен.
     */
    public function test_the_level_3_rows_sum_to_the_level_2_gross_when_the_roundings_disagree(): void
    {
        $run = $this->roundingClashRun();
        $row = $run->employees->first();

        // Случајот е репродуциран, не претпоставен: ако формулите се сменат и
        // овие бројки повеќе не се тие, тестот повеќе не го проверува она што
        // мисли дека го проверува и мора да падне тука, гласно.
        $this->assertSame(
            [32842.45, 1209.99, 164.21],
            $row->lines
                ->reject(fn (PayrollRunLine $line) => $line->kind === PayrollRunLine::KIND_DEDUCTION)
                ->map(fn (PayrollRunLine $line) => round($line->amount, 2))
                ->values()
                ->all(),
        );
        $this->assertSame(34216.65, round((float) $row->gross, 2));

        $xml = new \SimpleXMLElement(MpinDocumentBuilder::build($run));
        $employeeNode = $xml->MpinCalculationSt[0];

        $detailSum = array_sum(array_map('intval', $employeeNode->xpath('MpinCalculationStDetail/BrutoIznos')));

        $this->assertSame(34217, (int) $employeeNode->BrutoIznosVkVrab);
        $this->assertSame(
            (int) $employeeNode->BrutoIznosVkVrab,
            $detailSum,
            'Збирот на BrutoIznos на ниво 3 мора да е еднаков на BrutoIznosVkVrab на ниво 2.'
        );
    }

    /**
     * Денарот на разлика оди на најголемата ставка, не на првата: таму е
     * пропорционално најмалку видлив. Ова го фиксира изборот — без него
     * поправката би поминала и со остаток фрлен каде било.
     */
    public function test_the_rounding_remainder_lands_on_the_largest_level_3_row(): void
    {
        $xml = new \SimpleXMLElement(MpinDocumentBuilder::build($this->roundingClashRun()));
        $employeeNode = $xml->MpinCalculationSt[0];

        $amounts = array_map('intval', $employeeNode->xpath('MpinCalculationStDetail/BrutoIznos'));

        // 32 842,45 → 32 843 (го носи остатокот), 1 209,99 → 1 210, 164,21 → 164.
        $this->assertSame([32843, 1210, 164], $amounts);
    }

    /**
     * `createElement($name, $value)` не бега од знаци: вредност со „&" му
     * изгледа како почеток на ентитет, фрла предупредување и запишува ПРАЗЕН
     * елемент. Не е недостижен случај — `companies.tax_id` е слободен текст и
     * оди директно во `EdbIsplatitel`.
     */
    public function test_an_ampersand_in_a_value_is_escaped_not_dropped(): void
    {
        $company = Company::factory()->create([
            'name' => 'DESIGNIA DOOEL',
            'tax_id' => '4080000000000&1',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);

        $xml = MpinDocumentBuilder::build($this->confirmedRun($company, [
            'embg' => '0101990450006',
            'municipality_code' => '130',
            'health_area_code' => '4061',
            'bank_account' => '300000000000000',
            'insurance_type_code' => '0050',
            'movement_code' => '1',
            'weekly_hours' => 40,
            'employed_on' => '2026-01-01',
        ], 38507, 5));

        $this->assertStringContainsString('<EdbIsplatitel>4080000000000&amp;1</EdbIsplatitel>', $xml);
        $this->assertStringNotContainsString('<EdbIsplatitel></EdbIsplatitel>', $xml);

        // И назад: по парсирање вредноста мора да е точно она што е внесено.
        $parsed = new \SimpleXMLElement($xml);
        $this->assertSame('4080000000000&1', (string) $parsed->EdbIsplatitel);
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
