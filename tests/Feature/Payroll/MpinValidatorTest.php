<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\PayrollMonthHours;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\Mpin\MpinValidator;
use App\Support\Payroll\MpinObvrznik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Payroll\Concerns\BuildsMpinRuns;
use Tests\TestCase;

class MpinValidatorTest extends TestCase
{
    use BuildsMpinRuns, RefreshDatabase;

    public function test_a_sound_run_passes(): void
    {
        $result = MpinValidator::check($this->mpinRun());

        $this->assertTrue($result->passes());
        $this->assertSame([], $result->errors);
    }

    public function test_a_draft_run_is_not_exported(): void
    {
        PayrollMonthHours::firstOrCreate(
            ['year' => 2026, 'month' => 5],
            ['hours' => 168],
        );

        $company = Company::factory()->create([
            'mpin_obvrznik_code' => MpinObvrznik::EMPLOYER,
        ]);
        $run = app(PayrollRunService::class)->open($company, 2026, 5);

        $result = MpinValidator::check($run);

        $this->assertFalse($result->passes());
        $this->assertContains('Пресметката мора да биде потврдена пред извоз.', $result->errors);
    }

    public function test_a_company_without_an_obvrznik_type_is_not_exported(): void
    {
        $result = MpinValidator::check($this->mpinRun(['mpin_obvrznik_code' => null]));

        $this->assertContains('Фирмата нема внесен вид обврзник за МПИН.', $result->errors);
    }

    public function test_a_company_without_a_tax_id_is_not_exported(): void
    {
        $result = MpinValidator::check($this->mpinRun(['tax_id' => null]));

        $this->assertContains('Фирмата нема внесен ЕДБ.', $result->errors);
    }

    public function test_an_employee_without_a_health_area_is_not_exported(): void
    {
        $result = MpinValidator::check($this->mpinRun([], ['health_area_code' => null]));

        $this->assertContains(
            'Марко Петровски: нема внесена подрачна здравствена служба.',
            $result->errors,
        );
    }

    /**
     * Task 7-то откритие: SifraDvizenje доаѓа од movement_code, кое е nullable,
     * фабриката го остава null и формата за вработен го запишува null кога е
     * празно. Празно поле поминува низ градителот како валиден XML и е токму
     * она што УЈП го одбива при поднесување.
     */
    public function test_an_employee_without_a_movement_code_is_not_exported(): void
    {
        $result = MpinValidator::check($this->mpinRun([], ['movement_code' => null]));

        $this->assertContains(
            'Марко Петровски: нема внесена шифра на движење.',
            $result->errors,
        );
    }

    public function test_zero_days_of_service_blocks_the_export(): void
    {
        $run = $this->mpinRun();
        $run->employees->first()->update(['staz_days' => 0]);

        $result = MpinValidator::check($run->fresh());

        $this->assertContains(
            'Марко Петровски: нула денови стаж — датумите на вработување не го покриваат месецот.',
            $result->errors,
        );
    }

    public function test_a_part_time_code_on_a_full_fund_warns_but_does_not_block(): void
    {
        $result = MpinValidator::check($this->mpinRun([], ['insurance_type_code' => '0047']));

        $this->assertTrue($result->passes());
        $this->assertNotSame([], $result->warnings);
    }

    /**
     * Работник вработен на 15-ти не го покрива целиот месец по дизајн — ова е
     * нормален делумен месец (5c: партиални месеци), не пропуштени часови.
     * Ако предупредувањето за „часовите се помалку од фондот" не го земе ова
     * предвид, би пукало на секој нов вработен во текот на месецот — токму
     * бучавата што предупредувањата треба да ја избегнат.
     */
    public function test_a_mid_month_hire_does_not_warn_about_short_hours(): void
    {
        $result = MpinValidator::check($this->mpinRun([], ['employed_on' => '2026-05-15']));

        $this->assertSame([], $result->warnings);
    }
}
