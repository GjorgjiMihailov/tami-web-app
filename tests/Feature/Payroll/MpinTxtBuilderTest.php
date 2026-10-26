<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PayrollMonthHours;
use App\Models\PayrollParameter;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\Mpin\MpinTxtBuilder;
use App\Support\Payroll\MpinObvrznik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MpinTxtBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function company(array $overrides = []): Company
    {
        return Company::factory()->create([
            'name' => 'ТЕСТ ДООЕЛ Охрид',
            'tax_id' => '4020000000001',
            'registration_number' => '1234567',
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
            'payroll_phone_prefix' => '046',
            'payroll_phone' => '046000000',
            'street_address' => 'ЌУПРИЈА',
            'street_number' => '1/2',
            'city' => 'Охрид',
            'postal_code' => '6000',
            'payroll_municipality_code' => '152',
            'payroll_authorized_person' => 'Петар Петровски',
            'payroll_mobile' => '070000000',
            'email' => 'test@example.mk',
            ...$overrides,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function employee(Company $company, array $overrides, float $gross): Employee
    {
        $employee = Employee::factory()->for($company)->create([
            'municipality_code' => '152',
            'health_area_code' => '4081',
            'insurance_type_code' => '0050',
            'movement_code' => '1',
            'exemption_code' => null,
            'weekly_hours' => 40,
            'employed_on' => '2026-01-01',
            'terminated_on' => null,
            'prior_service_months' => 0,
            ...$overrides,
        ]);

        EmployeeSalary::create([
            'employee_id' => $employee->id,
            'effective_from' => '2024-01-01',
            'amount' => $gross,
            'basis' => 'gross',
        ]);

        return $employee;
    }

    private function confirm(Company $company, int $month, int $hours, int $year = 2026): PayrollRun
    {
        PayrollMonthHours::firstOrCreate(['year' => $year, 'month' => $month], ['hours' => $hours]);

        $user = User::factory()->create();
        $service = app(PayrollRunService::class);

        return $service->confirm($service->open($company, $year, $month), $user->id)->fresh();
    }

    /** Двајца работници на обврзник 110, јуни 2026 — еталонот. */
    private function juneRun(): PayrollRun
    {
        $company = $this->company();

        $this->employee($company, [
            'embg' => '0101990450006', 'first_name' => 'PETAR', 'last_name' => 'PETROVSKI',
            'bank_account' => '300000000000000',
        ], 38590);
        $this->employee($company, [
            'embg' => '1503880410003', 'first_name' => 'SOFIJA', 'last_name' => 'PETROVSKA',
            'municipality_code' => '179', 'bank_account' => '300000000000001',
        ], 38590);

        return $this->confirm($company, 6, 176);
    }

    private function crlfToLf(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }

    public function test_an_obvrznik_110_filing_is_reproduced_line_for_line(): void
    {
        $this->assertSame(
            file_get_contents(base_path('tests/Fixtures/mpin/obvrznik-110.txt')),
            $this->crlfToLf(MpinTxtBuilder::buildUtf8($this->juneRun())),
        );
    }

    /**
     * Обврзник 111 (адвокат), јули 2024 — вистинска датотека, анонимизирана.
     * Параметрите од тој месец се внесени тука зашто базата ги носи само
     * оние од 2026. Данокот е 0, колона 45 е 0.00, а колона 28 носи
     * ослободување 001 — три особености на 111.
     */
    public function test_an_obvrznik_111_filing_is_reproduced_line_for_line(): void
    {
        PayrollParameter::create([
            'effective_from' => '2024-07-01',
            'rate_pension' => 18.8, 'rate_health' => 7.5, 'rate_injury' => 0.5,
            'rate_unemployment' => 1.2, 'rate_tax' => 10,
            'personal_allowance' => 9540, 'average_salary' => 57609,
            'min_base' => 57609, 'max_base' => 1000000, 'minimum_wage' => 26000,
        ]);

        $company = $this->company([
            'name' => 'Адвокат Тест Тестовски',
            'tax_id' => '4090000000002',
            'registration_number' => '7654321',
            'mpin_obvrznik_code' => MpinObvrznik::SELF_EMPLOYED,
            'payroll_phone_prefix' => '02',
            'payroll_phone' => '022000000',
            'street_address' => 'ул. „Тест“ бр. 5',
            'street_number' => null,
            'city' => 'Скопје',
            'postal_code' => '1000',
            'payroll_municipality_code' => '182',
            'payroll_authorized_person' => 'Тест Тестовски',
            'email' => 'adv@example.mk',
        ]);

        $this->employee($company, [
            'embg' => '1503880410003', 'first_name' => 'TEST', 'last_name' => 'TESTOVSKI',
            'municipality_code' => '182', 'health_area_code' => '4061',
            'exemption_code' => '001', 'bank_account' => '300000000000001',
            'employed_on' => '2024-01-01',
        ], 57609);

        $run = $this->confirm($company, 7, 184, 2024);

        $this->assertSame(
            file_get_contents(base_path('tests/Fixtures/mpin/obvrznik-111.txt')),
            $this->crlfToLf(MpinTxtBuilder::buildUtf8($run)),
        );
    }

    public function test_the_file_is_windows_1251_with_crlf_line_endings(): void
    {
        $bytes = MpinTxtBuilder::build($this->juneRun());

        $this->assertFalse(mb_check_encoding($bytes, 'UTF-8'), 'кирилицата мора да е 1251, не UTF-8');
        $this->assertStringContainsString(
            mb_convert_encoding('Охрид', 'Windows-1251', 'UTF-8'),
            $bytes,
        );
        $this->assertStringEndsWith("1.0.3328.24828\r\n", $bytes);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $bytes), 'секој нов ред мора да е CRLF');
    }

    public function test_the_file_name_follows_the_old_programs_convention(): void
    {
        $this->assertSame('MPI3_4020000000001_2026_06_101_110.txt', MpinTxtBuilder::fileName($this->juneRun()));
    }

    public function test_a_clean_run_has_no_unsafe_text(): void
    {
        $this->assertSame([], MpinTxtBuilder::unsafeText($this->juneRun()));
    }

    public function test_a_semicolon_in_a_name_is_reported_not_written_silently(): void
    {
        $company = $this->company();
        $this->employee($company, [
            'embg' => '0101990450006', 'first_name' => 'PETAR;X', 'last_name' => 'PETROVSKI',
            'bank_account' => '300000000000000',
        ], 38590);

        $problems = MpinTxtBuilder::unsafeText($this->confirm($company, 6, 176));

        $this->assertNotEmpty($problems);
    }

    public function test_a_character_outside_windows_1251_is_reported(): void
    {
        $company = $this->company(['name' => 'ТЕСТ 日本 ДООЕЛ']);
        $this->employee($company, [
            'embg' => '0101990450006', 'first_name' => 'PETAR', 'last_name' => 'PETROVSKI',
            'bank_account' => '300000000000000',
        ], 38590);

        $problems = MpinTxtBuilder::unsafeText($this->confirm($company, 6, 176));

        $this->assertContains('има знаци што не постојат во Windows-1251', $problems);
    }

    public function test_the_july_rates_appear_in_the_parameter_line(): void
    {
        $company = $this->company();
        $this->employee($company, [
            'embg' => '0101990450006', 'first_name' => 'PETAR', 'last_name' => 'PETROVSKI',
            'bank_account' => '300000000000000',
        ], 38590);

        $text = MpinTxtBuilder::buildUtf8($this->confirm($company, 7, 184));

        $this->assertStringStartsWith("69141;10932;19.9;184;7.5;0.1;0.5;1;;;;;10;;;;;\r\n", $text);
    }

    /**
     * Колони 40–43 (вистински случаи): ден на вработување во месецот со 1 во
     * колона 42; ден на престанок со 2.
     */
    public function test_the_hire_and_the_termination_day_are_written_in_the_month_they_happen(): void
    {
        $company = $this->company();
        $this->employee($company, [
            'embg' => '0101990450006', 'first_name' => 'PETAR', 'last_name' => 'PETROVSKI',
            'bank_account' => '300000000000000', 'employed_on' => '2026-06-18',
        ], 38590);
        $this->employee($company, [
            'embg' => '1503880410003', 'first_name' => 'SOFIJA', 'last_name' => 'PETROVSKA',
            'bank_account' => '300000000000001', 'terminated_on' => '2026-06-29',
        ], 38590);
        $this->employee($company, [
            'embg' => '2002985450004', 'first_name' => 'IVAN', 'last_name' => 'PETROVSKI',
            'bank_account' => '300000000000002',
        ], 38590);

        $lines = explode("\r\n", MpinTxtBuilder::buildUtf8($this->confirm($company, 6, 176)));
        $columns = fn (int $line) => explode(';', $lines[$line]);

        $hired = $columns(3);
        $this->assertSame(['18', '', '1'], array_slice($hired, 39, 3));
        $this->assertSame(13, (int) $hired[7], 'денови стаж од 18-ти до 30-ти јуни');

        $ended = $columns(4);
        $this->assertSame(['', '29', '2'], array_slice($ended, 39, 3));

        $whole = $columns(5);
        $this->assertSame(['', '', ''], array_slice($whole, 39, 3));
    }

    public function test_every_employee_line_has_47_columns_and_the_totals_add_up(): void
    {
        $lines = explode("\r\n", MpinTxtBuilder::buildUtf8($this->juneRun()));

        foreach ([3, 4] as $i) {
            $this->assertCount(47, explode(';', $lines[$i]));
        }

        $totals = explode(';', $lines[7]);
        $this->assertCount(18, $totals);
        // Вкупната обврска = придонеси + данок (редот 2 и редот 7 ја носат истата).
        $this->assertSame('06;2026;101;110;2;24,980.00;', $lines[1]);
        $due = $totals[3] + $totals[8] + $totals[10] + $totals[12] + $totals[14];
        $this->assertEquals(24980, $due);
    }
}
