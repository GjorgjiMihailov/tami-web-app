<?php

namespace App\Support\Payroll\Mpin;

use App\Models\PayrollRun;
use App\Models\PayrollRunLine;

/**
 * Проверките што УЈП сама ги врти по поднесување, направени пред симнување.
 *
 * Изворот е `poraki.html` од помошта на МПИН клиентот, каде секоја порака носи
 * тежина: 2 = грешка, 1 = предупредување. Истата поделба се задржува овде, за
 * да не се блокира извоз поради нешто што УЈП само би го предупредила.
 */
final class MpinValidator
{
    public static function check(PayrollRun $run): MpinValidationResult
    {
        $run->loadMissing(['company', 'employees.employee', 'employees.lines']);

        $errors = [];
        $warnings = [];

        if ($run->isDraft()) {
            $errors[] = 'Пресметката мора да биде потврдена пред извоз.';
        }

        if (! $run->company->tax_id) {
            $errors[] = 'Фирмата нема внесен ЕДБ.';
        }

        if (! $run->company->mpin_obvrznik_code) {
            $errors[] = 'Фирмата нема внесен вид обврзник за МПИН.';
        }

        if ($run->employees->isEmpty()) {
            $errors[] = 'Пресметката нема ниту еден работник.';
        }

        foreach ($run->employees as $row) {
            $employee = $row->employee;
            $name = trim($employee->first_name.' '.$employee->last_name);

            foreach ([
                'embg' => 'ЕМБГ',
                'bank_account' => 'трансакциска сметка',
                'municipality_code' => 'шифра на општина',
                'health_area_code' => 'подрачна здравствена служба',
                'insurance_type_code' => 'шифра за вид на стаж',
                // Task 7-то откритие: SifraDvizenje доаѓа од movement_code,
                // кое е nullable, дефолт null во фабриката и во формата кога е
                // празно. Празно поле поминува низ градителот како валиден
                // XML — <SifraDvizenje></SifraDvizenje> — и УЈП го одбива при
                // поднесување.
                'movement_code' => 'шифра на движење',
            ] as $column => $label) {
                if (! $employee->{$column}) {
                    $errors[] = "{$name}: нема внесена {$label}.";
                }
            }

            // Нулата е намерна ознака за аномалија од фазата за делумни месеци:
            // работник чии датуми се сменети откако пресметката е отворена. УЈП
            // фиксира вредност меѓу 1 и бројот денови во месецот, па нулата е
            // нелегална и мора да запре тука.
            if ($row->staz_days < 1) {
                $errors[] = "{$name}: нула денови стаж — датумите на вработување не го покриваат месецот.";
            }

            if (round($row->gross) <= 0) {
                $errors[] = "{$name}: бруто износот е нула, што УЈП не го прифаќа без потврда.";
            }

            $hourLines = $row->lines->where('kind', PayrollRunLine::KIND_HOURS);

            if ($hourLines->isEmpty()) {
                $errors[] = "{$name}: нема ниту една линија со часови.";
            }

            foreach ($hourLines as $line) {
                if ((int) $line->hours > 0 && round($line->amount) <= 0) {
                    $errors[] = "{$name}: линијата „{$line->description}“ има часови без износ.";
                }

                if ((int) $line->hours <= 0 && round($line->amount) > 0) {
                    $errors[] = "{$name}: линијата „{$line->description}“ има износ без часови.";
                }
            }

            // Предупредување, не грешка: работник со полно работно време што
            // бил на боледување легитимно има помалку часови од фондот.
            $fund = $employee->monthFund($run->month_hours);
            $worked = (int) $hourLines->sum('hours');

            if ($employee->insurance_type_code === '0047' && $worked >= $run->month_hours) {
                $warnings[] = "{$name}: шифрата 0047 значи неполно работно време, а часовите се како за полно.";
            }

            // Ограничено на цел месец покриеност намерно: делумен месец (нов
            // вработен или заминат во текот на месецот) по дизајн носи помалку
            // часови од целиот фонд — MonthCoverage::hours() го смета токму
            // тоа отстапување, не грешка. Да се предупредува на секој делумен
            // месец би било предупредување на секој нов/заминат вработен, а
            // тоа е бучава што учи луѓето да ги игнорираат предупредувањата.
            $coversWholeMonth = $employee->coverageIn($run->year, $run->month)->isFullMonth();

            if ($employee->insurance_type_code === '0050' && $coversWholeMonth && $worked < $fund) {
                $warnings[] = "{$name}: шифрата 0050 значи полно работно време, а часовите се помалку од фондот.";
            }
        }

        return new MpinValidationResult(array_values($errors), array_values($warnings));
    }
}
