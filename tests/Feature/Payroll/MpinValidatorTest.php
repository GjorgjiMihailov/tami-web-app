<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\PayrollMonthHours;
use App\Models\PayrollRunLine;
use App\Services\Payroll\PayrollRunService;
use App\Support\Payroll\Mpin\MpinDocumentBuilder;
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

    /**
     * insurance_type_code 0047 (неполно работно време) со weekly_hours сепак
     * на 40 дејствуваат два сигнала одеднаш: стариот (внесените часови оваа
     * пресметка сепак излегуваат на ниво на цел фонд) и директната
     * противречност шифра/картон подолу (0047 со неделен фонд од 40 часа).
     * Двете assertContains тврдења овде докажуваат дека и двете сè уште
     * постојат.
     */
    public function test_a_part_time_code_on_a_full_fund_warns_but_does_not_block(): void
    {
        $result = MpinValidator::check($this->mpinRun([], ['insurance_type_code' => '0047']));

        $this->assertTrue($result->passes());
        $this->assertContains(
            'Марко Петровски: шифрата 0047 значи неполно работно време, а часовите се како за полно.',
            $result->warnings,
        );
        $this->assertContains(
            'Марко Петровски: шифрата 0047 значи неполно работно време, а во картонот на вработениот неделниот фонд е 40 часа.',
            $result->warnings,
        );
    }

    /**
     * Работник вработен на 15-ти не го покрива целиот месец по дизајн — ова е
     * нормален делумен месец (5c: партиални месеци), не пропуштени часови.
     * И очекуваните и вистинските часови се сведени по истото правило на
     * MonthCoverage, па се совпаѓаат точно и предупредувањето молчи —
     * наместо да пука на секој нов вработен во текот на месецот, што би била
     * бучава што учи луѓето да ги игнорираат предупредувањата.
     */
    public function test_a_mid_month_hire_does_not_warn_about_short_hours(): void
    {
        $result = MpinValidator::check($this->mpinRun([], ['employed_on' => '2026-05-15']));

        $this->assertSame([], $result->warnings);
    }

    /**
     * Спротивниот случај од претходниот тест: истиот делумен месец, но
     * часовите на линијата се рачно намалени под сведеното очекување (на
     * пример, неплатено отсуство). Гејт на цел месец (isFullMonth) целосно ќе
     * го изгасеше ова предупредување за секој делумен месец — сведувањето на
     * очекувањето наместо тоа го задржува сигналот.
     */
    public function test_a_mid_month_hire_with_short_hours_still_warns(): void
    {
        $run = $this->mpinRun([], ['employed_on' => '2026-05-15']);

        $run->employees->first()->lines
            ->firstWhere('kind', PayrollRunLine::KIND_HOURS)
            ->update(['hours' => 1]);

        $result = MpinValidator::check($run->fresh());

        $this->assertContains(
            'Марко Петровски: шифрата 0050 значи полно работно време, а часовите се помалку од очекуваните за покриениот период.',
            $result->warnings,
        );
    }

    /**
     * Обврзник 111 го пишува придонесот за вработување нула ПО ОСНОВ на
     * шифрата за ослободување 001 — тоа е она што ја оправдува нулата пред
     * УЈП. Празен `SifraOsloboduvanje` со нулти придонес е добро оформена
     * датотека што сепак се одбива.
     */
    public function test_an_obvrznik_111_employee_without_an_exemption_code_is_not_exported(): void
    {
        $result = MpinValidator::check($this->mpinRun(
            ['mpin_obvrznik_code' => MpinObvrznik::SELF_EMPLOYED],
            ['exemption_code' => null],
        ));

        $this->assertContains(
            'Марко Петровски: нема внесена шифра за ослободување, а обврзник 111 го пишува придонесот за вработување нула токму по неа.',
            $result->errors,
        );
    }

    public function test_an_obvrznik_111_employee_with_an_exemption_code_passes(): void
    {
        $result = MpinValidator::check($this->mpinRun(
            ['mpin_obvrznik_code' => MpinObvrznik::SELF_EMPLOYED],
            ['exemption_code' => '001'],
        ));

        $this->assertTrue($result->passes(), implode(' | ', $result->errors));
    }

    /**
     * Правилото важи САМО за 111: вистинската прифатена датотека за обврзник
     * 110 носи празен `SifraOsloboduvanje`, па безусловно правило би скршило
     * филинг што УЈП веќе го прифатила.
     */
    public function test_an_obvrznik_110_employee_without_an_exemption_code_still_passes(): void
    {
        $result = MpinValidator::check($this->mpinRun([], ['exemption_code' => null]));

        $this->assertTrue($result->passes(), implode(' | ', $result->errors));
    }

    /**
     * Заштитна мрежа за расчекор што зачувувањето на видот обврзник на самата
     * пресметка не го покрива: пресметка означена како 111 чии зачувани цифри
     * се на 110.
     */
    public function test_an_obvrznik_111_run_carrying_110_figures_is_not_exported(): void
    {
        $run = $this->mpinRun();
        $run->forceFill(['mpin_obvrznik_code' => MpinObvrznik::SELF_EMPLOYED])->save();

        $result = MpinValidator::check($run->fresh());

        $this->assertContains(
            'Марко Петровски: пресметката е означена како обврзник 111, а носи придонес за вработување или личен данок, што 111 не плаќа — избришете ја пресметката и отворете ја повторно.',
            $result->errors,
        );
    }

    public function test_an_obvrznik_110_run_without_unemployment_is_not_exported(): void
    {
        $run = $this->mpinRun();
        $run->employees->first()->update(['unemployment' => 0]);

        $result = MpinValidator::check($run->fresh());

        $this->assertContains(
            'Марко Петровски: пресметката е означена како обврзник 110, а придонесот за вработување е нула — избришете ја пресметката и отворете ја повторно.',
            $result->errors,
        );
    }

    /**
     * Огледален случај на 0047-со-40-часа: 0050 (полно работно време) чиј
     * договорен неделен фонд во картонот на вработениот е под 40 часа.
     * monthFund() го сведува фондот по weekly_hours без оглед на шифрата, па
     * часовите излегуваат точно колку „треба" за 20 часа неделно и другите две
     * предупредувања молчат — само директната проверка шифра-наспроти-картон
     * го фаќа ова.
     */
    public function test_a_full_time_code_with_part_time_hours_warns(): void
    {
        $result = MpinValidator::check($this->mpinRun([], ['weekly_hours' => 20]));

        $this->assertTrue($result->passes());
        $this->assertContains(
            'Марко Петровски: шифрата 0050 значи полно работно време, а во картонот на вработениот неделниот фонд е под 40 часа.',
            $result->warnings,
        );
    }

    /**
     * Проверката од спецификацијата („збировите на трите нивоа се совпаѓаат до
     * денар") се врти врз ПРОИЗВЕДЕНИОТ XML, не врз повторена пресметка на
     * истата аритметика: повторувањето на она што градителот го прави е
     * тавтологија што не може да падне, а проверка што не може да падне е
     * полоша од ниедна. Овде намерно се внесува расчекор во веќе изградената
     * датотека, за да се докаже дека проверката навистина фаќа.
     */
    public function test_the_level_sum_check_catches_a_level_3_row_that_does_not_add_up(): void
    {
        $xml = MpinDocumentBuilder::build($this->mpinRun());

        $broken = str_replace(
            '<BrutoIznos>38507</BrutoIznos>',
            '<BrutoIznos>38506</BrutoIznos>',
            $xml,
        );

        $this->assertNotSame($xml, $broken);
        $this->assertSame(
            ['Работник со реден број 1: бруто износот е 38507, а збирот на неговите ставки е 38506.'],
            MpinValidator::levelSumErrors($broken),
        );
    }

    public function test_the_level_sum_check_catches_a_total_that_does_not_add_up(): void
    {
        $xml = MpinDocumentBuilder::build($this->mpinRun());

        $broken = str_replace(
            '<NetoIznosVk>26046</NetoIznosVk>',
            '<NetoIznosVk>26045</NetoIznosVk>',
            $xml,
        );

        $this->assertNotSame($xml, $broken);
        $this->assertSame(
            ['Вкупниот износ NetoIznosVk е 26045, а збирот по работници е 26046.'],
            MpinValidator::levelSumErrors($broken),
        );
    }

    public function test_a_sound_document_has_no_level_sum_errors(): void
    {
        $this->assertSame(
            [],
            MpinValidator::levelSumErrors(MpinDocumentBuilder::build($this->mpinRun())),
        );
    }

    /**
     * Проверката е вклучена во check(), не само достапна одвоено — и тоа врз
     * точно оној облик што ја роди: пресметка чии заокружувања на ниво 2 и
     * ниво 3 се разидуваат. Со вратена стара `detailNode` аритметика овој тест
     * паѓа со „бруто износот е 34217, а збирот на неговите ставки е 34216",
     * што е целата поента на оваа проверка.
     *
     * Не се тврди дека целата пресметка поминува: разидувањето се создава со
     * линија минат труд, а таквата линија ја запира привремената брана подолу.
     * Тука се тврди дека НЕМА грешка за порамнување на нивоата — точно она што
     * оваа проверка го чува. Кога браната ќе се тргне, ова се враќа на
     * passes().
     */
    public function test_the_level_sum_check_guards_a_run_whose_roundings_disagree(): void
    {
        $errors = MpinValidator::check($this->roundingClashRun())->errors;

        $levelSumErrors = array_filter(
            $errors,
            fn (string $error) => str_contains($error, 'збирот на неговите ставки'),
        );

        $this->assertSame([], array_values($levelSumErrors), implode(' | ', $errors));
    }

    /**
     * Привремена брана, не трајно правило.
     *
     * Работник со завршена година стаж добива линија „Минат труд“ — износ без
     * часови — која градителот ја пишува како засебен ред на ниво 3 со нула
     * часови и нула денови стаж. Нула денови стаж е вредност што овој ист
     * валидатор ја смета за нелегална едно ниво погоре, а ниту една од двете
     * вистински поднесени датотеки не го покрива тој пат: обете ставаат цело
     * бруто во еден ред и обете се за работник без стаж.
     *
     * Значи обликот е изведен, не видуван. Додека не пристигне вистинска
     * датотека со минат труд или надоместок, извозот застанува тука наместо да
     * погоди во документ што оди во УЈП.
     */
    public function test_a_line_without_hours_blocks_the_export_until_a_real_filing_confirms_its_shape(): void
    {
        $result = MpinValidator::check($this->mpinRun([], ['prior_service_months' => 12]));

        $this->assertFalse($result->passes());
        $this->assertContains(
            'Марко Петровски: линијата „Минат труд“ е износ без часови, а обликот на таквиот ред во МПИН сè уште не е потврден со вистинска поднесена датотека — извозот е запрен намерно.',
            $result->errors,
        );
    }

    public function test_an_ordinary_run_without_such_a_line_still_passes(): void
    {
        $result = MpinValidator::check($this->mpinRun());

        $this->assertTrue($result->passes(), implode(' | ', $result->errors));
    }

    /**
     * Пресметката точно го извезува видот обврзник по кој е пресметана, дури и
     * кога картонот на фирмата во меѓувреме е сменет — потврдена пресметка
     * одбива да се пресмета повторно, па 110 е единствениот документ што таа
     * пресметка може да го произведе без сама да си противречи.
     *
     * Тивко е она што е проблем: сметководителот што свесно го сменил картонот
     * на 111 симнува датотека со заглавие 110 и ништо не му кажува зошто.
     * Предупредување, не грешка — датотеката е исправна.
     */
    public function test_a_run_whose_obvrznik_no_longer_matches_the_company_warns(): void
    {
        $run = $this->mpinRun();

        $run->company->update(['mpin_obvrznik_code' => MpinObvrznik::SELF_EMPLOYED]);

        $result = MpinValidator::check($run->fresh());

        $this->assertTrue($result->passes(), implode(' | ', $result->errors));
        $this->assertContains(
            'Оваа пресметка е пресметана како обврзник 110, а фирмата сега е означена како 111. Датотеката се извезува како 110; новиот вид важи од следната пресметка.',
            $result->warnings,
        );
    }
}
