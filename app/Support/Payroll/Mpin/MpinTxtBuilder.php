<?php

namespace App\Support\Payroll\Mpin;

use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollRunLine;
use App\Support\Payroll\MpinObvrznik;
use Carbon\CarbonImmutable;

/**
 * Гради МПИН .txt (МПИ3) во стариот формат — истиот што го прави стариот
 * софтвер пред да го шифрира во .crp.
 *
 * Формат: Windows-1251, полиња одделени со „;", редови завршени со CRLF.
 * Распоредот на колоните не е од документација (таква нема во помошта на УЈП),
 * туку од 95 вистински поднесени датотеки во C:\MPPP-prijavi: збировите на
 * заглавните редови се проверени против работниците во секоја од нив, а
 * колоните 12/44/45 и 40–43 против вистински случаи (скратен месец, престанок,
 * обврзник 111). Не се „поправа" ништо што изгледа чудно — износите со две
 * децимали, празните колони и константите стојат така во секоја датотека.
 */
final class MpinTxtBuilder
{
    /** Видот на обврска за редовна месечна плата. */
    public const VID_OBVRSKA = '101';

    /** Верзијата на стариот софтвер — истата во сите 95 вистински датотеки. */
    private const PROGRAM_VERSION = '1.0.3328.24828';

    private const SEPARATOR_LINE = '***********************************';

    private const ENCODING = 'Windows-1251';

    public static function fileName(PayrollRun $run): string
    {
        return sprintf(
            'MPI3_%s_%d_%02d_%s_%s.txt',
            $run->company->tax_id,
            $run->year,
            $run->month,
            self::VID_OBVRSKA,
            ($run->mpin_obvrznik_code ?? MpinObvrznik::EMPLOYER)->value,
        );
    }

    /** Содржината како Windows-1251 бајти, подготвена за запишување. */
    public static function build(PayrollRun $run): string
    {
        return mb_convert_encoding(self::buildUtf8($run), self::ENCODING, 'UTF-8');
    }

    /** Истата содржина како UTF-8, за тестовите и за проверка на знаците. */
    public static function buildUtf8(PayrollRun $run): string
    {
        $run->loadMissing(['company', 'parameter', 'employees.employee', 'employees.lines']);

        $obvrznik = $run->mpin_obvrznik_code ?? MpinObvrznik::EMPLOYER;
        $rows = $run->employees->values();

        $amounts = $rows->map(fn (PayrollRunEmployee $row) => MpinDocumentBuilder::amounts($row, $obvrznik));
        $sum = fn (string $key): int => (int) $amounts->sum($key);

        // Придонеси + данок: она што се плаќа до државата. Дополнителни
        // придонеси (Dop…) се секогаш 0 кај нас, па не влегуваат.
        $due = $sum('pension') + $sum('health') + $sum('injury') + $sum('unemployment') + $sum('tax');

        $lines = [
            self::parameterLine($run),
            implode(';', [
                sprintf('%02d', $run->month), $run->year, self::VID_OBVRSKA, $obvrznik->value,
                $rows->count(), self::grouped($due), '',
            ]),
            self::companyLine($run),
        ];

        foreach ($rows as $index => $row) {
            $lines[] = self::employeeLine($run, $row, $index + 1, $amounts[$index], $obvrznik);
        }

        $lines[] = self::SEPARATOR_LINE;
        $lines[] = implode(';', [self::VID_OBVRSKA, $obvrznik->value, $rows->count(), self::grouped($due), '']);

        // Редоследот е оној од колоните на работникот (12, 15, 17…26, 38, 44);
        // трите нули по „доплатен ПИО" се колони што кај нас никогаш не се
        // пополнуваат, а претпоследната е колона 38.
        $lines[] = implode(';', [
            self::money($sum('net')),
            self::money(0),
            self::money($sum('gross')),
            self::money($sum('pension')),
            self::money(0),
            self::money(0),
            self::money(0),
            self::money(0),
            self::money($sum('health')),
            self::money(0),
            self::money($sum('injury')),
            self::money(0),
            self::money($sum('unemployment')),
            self::money(0),
            self::money($sum('tax')),
            self::money(0),
            self::money($sum('net')),
            '',
        ]);
        $lines[] = self::PROGRAM_VERSION;

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * Знаците што не можат да се запишат во Windows-1251 или го кршат
     * форматот (точка-запирка, нов ред). Празна листа значи дека е чисто.
     *
     * @return list<string>
     */
    public static function unsafeText(PayrollRun $run): array
    {
        $text = self::buildUtf8($run);
        $problems = [];

        // Секое поле на пишан текст ги доведува „;" што не се на нас:
        // излезот има фиксен број колони по ред, па вишок го покажува.
        foreach (explode("\r\n", $text) as $number => $line) {
            if ($line === '' || str_starts_with($line, '***')) {
                continue;
            }

            if (self::columnCount($line, $number) !== null) {
                $problems[] = 'ред '.($number + 1).' има погрешен број колони (текстот содржи „;“)';
            }
        }

        $roundTrip = mb_convert_encoding(
            mb_convert_encoding($text, self::ENCODING, 'UTF-8'),
            'UTF-8',
            self::ENCODING,
        );

        if ($roundTrip !== $text) {
            $problems[] = 'има знаци што не постојат во Windows-1251';
        }

        return $problems;
    }

    /**
     * Вистинскиот број колони по ред: 17+1 (параметри), 5+1+1, 15+1 (фирма),
     * 46+1 (работник). Враќа null кога редот е исправен.
     */
    private static function columnCount(string $line, int $index): ?int
    {
        $expected = match (true) {
            $index === 0 => 18,
            $index === 1 => 7,
            $index === 2 => 16,
            default => null,
        };

        if ($expected === null) {
            if (preg_match('/^\d+;\d{13};/', $line)) {
                $expected = 47;
            } else {
                return null;
            }
        }

        return substr_count($line, ';') + 1 === $expected ? null : substr_count($line, ';') + 1;
    }

    private static function parameterLine(PayrollRun $run): string
    {
        $parameter = $run->parameter;

        return implode(';', [
            self::plain($parameter->average_salary),
            self::plain($parameter->personal_allowance),
            self::plain($parameter->rate_pension),
            $run->month_hours,
            self::plain($parameter->rate_health),
            self::plain($parameter->rate_unemployment),
            self::plain($parameter->rate_injury),
            '1', '', '', '', '',
            self::plain($parameter->rate_tax),
            '', '', '', '', '',
        ]);
    }

    private static function companyLine(PayrollRun $run): string
    {
        $company = $run->company;

        return implode(';', [
            $company->tax_id,
            $company->registration_number,
            $company->name,
            $company->payroll_phone_prefix,
            $company->payroll_phone,
            '',
            $company->street_address ?: $company->address,
            $company->street_number,
            $company->city,
            $company->city,
            $company->payroll_municipality_code,
            $company->postal_code,
            $company->payroll_authorized_person,
            $company->payroll_mobile,
            $company->email,
            '',
        ]);
    }

    /** @param  array<string, int>  $amounts */
    private static function employeeLine(
        PayrollRun $run,
        PayrollRunEmployee $row,
        int $ordinal,
        array $amounts,
        MpinObvrznik $obvrznik,
    ): string {
        $employee = $row->employee;

        $detail = $row->lines
            ->reject(fn (PayrollRunLine $line) => $line->kind === PayrollRunLine::KIND_DEDUCTION)
            ->values();

        $monthStart = CarbonImmutable::create($run->year, $run->month, 1);
        $monthEnd = $monthStart->endOfMonth();
        $hired = $employee->employed_on ? CarbonImmutable::parse($employee->employed_on) : null;
        $ended = $employee->terminated_on ? CarbonImmutable::parse($employee->terminated_on) : null;

        // Денот на вработување / престанок се пишува само во месецот кога се
        // случува; во колона 42 стои 2 за престанок (тој има предност) и 1 за
        // вработување.
        $hiredInMonth = $hired && $hired->betweenIncluded($monthStart, $monthEnd);
        $endedInMonth = $ended && $ended->betweenIncluded($monthStart, $monthEnd);

        return implode(';', [
            $ordinal,
            $employee->embg,
            $employee->last_name,
            $employee->first_name,
            $detail->first()?->code ?? '001',
            $employee->health_area_code,
            $employee->municipality_code,
            $row->staz_days,
            (string) $detail->sum('hours'),
            '', '',
            self::money($amounts['net']),
            '', '',
            self::money(0),
            '',
            self::money($amounts['gross']),
            self::money($amounts['pension']),
            self::money(0),
            self::money($amounts['health']),
            self::money(0),
            self::money($amounts['injury']),
            self::money(0),
            self::money($amounts['unemployment']),
            self::money(0),
            self::money($amounts['tax']),
            $employee->insurance_type_code,
            $employee->exemption_code,
            '', '', '', '',
            self::money(0), self::money(0), self::money(0),
            '', '',
            self::money(0),
            '',
            $hiredInMonth ? $hired->format('d') : '',
            $endedInMonth ? $ended->format('d') : '',
            $endedInMonth ? '2' : ($hiredInMonth ? '1' : ''),
            $employee->movement_code,
            self::money($amounts['net']),
            self::money($amounts['effectiveNet']),
            $employee->bank_account,
            '',
        ]);
    }

    /** Износ со две децимали, без разделник за илјади: 26100.00 */
    private static function money(int|float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /** Износ за вкупната обврска: со запирка за илјади, 24,980.00 */
    private static function grouped(int|float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    /** Стапка/износ без залудни нули: 18.8, 7.5, 10, 69141 */
    private static function plain(int|float $value): string
    {
        $text = number_format((float) $value, 3, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }
}
