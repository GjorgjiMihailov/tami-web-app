<?php

namespace App\Support\Payroll\Mpin;

use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Models\PayrollRunLine;
use App\Support\Payroll\MpinObvrznik;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;

/**
 * Гради МПИН XML од потврдена пресметка.
 *
 * Форматите не се измислени: секој е препишан од две вистински датотеки што
 * УЈП ги прифатила, и еталон-тестовите бараат излезот да им биде знак по знак
 * ист. Ниту едно правило овде не смее да се „поправи" затоа што изгледа чудно
 * — денот без водечка нула наспроти месецот со неа изгледа како грешка и не е.
 */
final class MpinDocumentBuilder
{
    /** Видот на обврска за редовна месечна плата. */
    public const VID_OBVRSKA = '101';

    public static function fileName(PayrollRun $run): string
    {
        return sprintf(
            '%s_%d_%02d_%s.xml',
            $run->company->name,
            $run->year,
            $run->month,
            self::VID_OBVRSKA,
        );
    }

    public static function build(PayrollRun $run): string
    {
        $run->loadMissing(['company', 'employees.employee', 'employees.lines']);

        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('MpinCalculation');
        // Редоследот на овие два атрибути е тој од вистинските датотеки.
        // setAttribute го чува редоследот на внесување; setAttributeNS не го чува.
        $root->setAttribute('xsi:noNamespaceSchemaLocation', 'schema.xsd');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $dom->appendChild($root);

        $obvrznik = $run->company->mpin_obvrznik_code ?? MpinObvrznik::EMPLOYER;
        $rows = $run->employees->values();
        $totals = self::totals($rows, $obvrznik);

        self::add($dom, $root, 'EdbIsplatitel', (string) $run->company->tax_id);
        self::add($dom, $root, 'SifraVidObvrznik', $obvrznik->value);
        self::add($dom, $root, 'VoImeNaObvrznikEdb', (string) $run->company->tax_id);
        self::add($dom, $root, 'SifraVidObvrska', self::VID_OBVRSKA);
        self::add($dom, $root, 'MesecPridonesi', sprintf('%02d', $run->month));
        self::add($dom, $root, 'GodinaPridonesi', (string) $run->year);
        self::add($dom, $root, 'BrojVraboteni', (string) $rows->count());

        foreach (self::AMOUNT_FIELDS as $suffix => $key) {
            self::add($dom, $root, $suffix.'Vk', (string) $totals[$key]);
        }

        self::add($dom, $root, 'Zabeleska', '');

        foreach ($rows as $index => $row) {
            $root->appendChild(self::employeeNode($dom, $run, $row, $index + 1, $obvrznik));
        }

        return $dom->saveXML(null, LIBXML_NOEMPTYTAG);
    }

    /**
     * Име на полето во XML (без наставката) => клуч во нашата пресметка.
     *
     * Редоследот е обврзувачки: шемата е `xs:sequence`, значи редоследот на
     * полињата е дел од валидноста, не козметика.
     *
     * @var array<string, string>
     */
    private const AMOUNT_FIELDS = [
        'BrutoIznos' => 'gross',
        'ZadPIOIznos' => 'pension',
        'DopPIOIznos' => 'zero',
        'ZadFZOIznos' => 'health',
        'DopFZOIznos' => 'zero',
        'ZadPovredaRabIznos' => 'injury',
        'DopPovredaRabIznos' => 'zero',
        'ZadVrabotuvanjeIznos' => 'unemployment',
        'DopVrabotuvanjeIznos' => 'zero',
        'ZadBenefStazIznos' => 'zero',
        'DopBenefStazIznos' => 'zero',
        'PersonalenDanokIznos' => 'tax',
        'NetoIznos' => 'net',
        'EfektivnoNetoIznos' => 'effectiveNet',
        'DanocnoOslobIznos' => 'taxRelief',
    ];

    /** @return array<string, int> */
    private static function amounts(PayrollRunEmployee $row, MpinObvrznik $obvrznik): array
    {
        // Даночното намалување не се чува како колона зашто не мора:
        // taxBase = max(gross − contributions − allowance, 0), па
        // gross − contributions − taxBase е точно искористеното намалување.
        // За обврзник 111 даночната основица е нула по дефиниција, па
        // формулата не важи и се пишува буквална нула.
        $taxRelief = $obvrznik->chargesMonthlyTax()
            ? (int) round($row->gross - $row->contributions - $row->tax_base)
            : 0;

        // Ефективното нето е нето минус задршки — така го дефинира помошта на
        // МПИН клиентот и така стои во вистинската датотека за обврзник 110.
        // Вистинската датотека за 111 пишува нула иако нето-то не е нула;
        // тоа е особеност на таа категорија, потврдена со примерок.
        $effectiveNet = $obvrznik->chargesMonthlyTax()
            ? (int) round($row->effective_net)
            : 0;

        return [
            'gross' => (int) round($row->gross),
            'pension' => (int) round($row->pension),
            'health' => (int) round($row->health),
            'injury' => (int) round($row->injury),
            'unemployment' => (int) round($row->unemployment),
            'tax' => (int) round($row->tax),
            'net' => (int) round($row->net),
            'effectiveNet' => $effectiveNet,
            'taxRelief' => $taxRelief,
            'zero' => 0,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PayrollRunEmployee>  $rows
     * @return array<string, int>
     */
    private static function totals($rows, MpinObvrznik $obvrznik): array
    {
        // Збир на веќе заокружените износи, не заокружен збир на неокружените.
        // Инаку ниво 1 нема да се совпадне со збирот на ниво 2, а УЈП го
        // проверува тоа.
        // Почнува од нули, за да ги испише сите полиња и кога пресметката нема
        // ниту еден работник. Валидаторот ја одбива таквата, но градителот не
        // смее да падне на неа.
        $totals = array_fill_keys(array_values(self::AMOUNT_FIELDS), 0);

        foreach ($rows as $row) {
            foreach (self::amounts($row, $obvrznik) as $key => $value) {
                $totals[$key] += $value;
            }
        }

        $totals['zero'] = 0;

        return $totals;
    }

    private static function employeeNode(
        DOMDocument $dom,
        PayrollRun $run,
        PayrollRunEmployee $row,
        int $ordinal,
        MpinObvrznik $obvrznik,
    ): DOMElement {
        $node = $dom->createElement('MpinCalculationSt');
        $employee = $row->employee;
        $amounts = self::amounts($row, $obvrznik);

        // Ниво 3 мора да збирува точно до ниво 2 (BrutoIznosVkVrab = $row->gross),
        // а $row->gross ги вклучува и не-часовните ставки (на пр. минат труд,
        // KIND_AMOUNT). Затоа тука не се филтрира само KIND_HOURS — шифрарникот
        // rab_cas на УЈП за SifraTipRabotenCas ги држи истите шифри во еден
        // список (001 редовни часови, 037 минат труд, 034 награда - износ, …),
        // значи ставка со износ без часови е легитимен ред на ниво 3, не
        // исклучок. Задршките (KIND_DEDUCTION) го намалуваат нетото, не
        // брутото — $row->gross веќе не ги содржи, па остануваат надвор.
        $detailLines = $row->lines
            ->reject(fn (PayrollRunLine $line) => $line->kind === PayrollRunLine::KIND_DEDUCTION)
            ->values();

        self::add($dom, $node, 'RedenBroj', (string) $ordinal);
        self::add($dom, $node, 'VrabotenEmbg', (string) $employee->embg);
        self::add($dom, $node, 'SifraOpstina', (string) $employee->municipality_code);
        self::add($dom, $node, 'TransakciskaSmetka', (string) $employee->bank_account);
        self::add($dom, $node, 'DenoviStazVkVrab', (string) $row->staz_days);

        foreach (self::AMOUNT_FIELDS as $suffix => $key) {
            self::add($dom, $node, $suffix.'VkVrab', (string) $amounts[$key]);
        }

        // Ставките со износ (на пр. минат труд) немаат часови (hours е null),
        // па придонесуваат 0 во овој збир — вредноста останува иста како кога
        // се сумираа само KIND_HOURS редовите.
        self::add($dom, $node, 'RabotniCasoviVkVrab', (string) $detailLines->sum('hours'));

        foreach ($detailLines as $index => $line) {
            $node->appendChild(self::detailNode(
                $dom,
                $run,
                $row,
                $line,
                $index + 1,
                // Сите денови стаж одат на првата линија со часови, а остатокот
                // добива нула, така што збирот на ниво 3 останува еднаков на
                // ниво 2. Поделбата на повеќе линии не е потврдена од ниту еден
                // примерок — види го отвореното прашање во спецификацијата.
                $index === 0 ? $row->staz_days : 0,
            ));
        }

        return $node;
    }

    private static function detailNode(
        DOMDocument $dom,
        PayrollRun $run,
        PayrollRunEmployee $row,
        PayrollRunLine $line,
        int $ordinal,
        int $stazDays,
    ): DOMElement {
        $node = $dom->createElement('MpinCalculationStDetail');
        $employee = $row->employee;

        $monthStart = CarbonImmutable::create($run->year, $run->month, 1);
        $monthEnd = $monthStart->endOfMonth();

        $from = CarbonImmutable::parse($employee->employed_on)->max($monthStart);
        $to = $employee->terminated_on
            ? CarbonImmutable::parse($employee->terminated_on)->min($monthEnd)
            : $monthEnd;

        self::add($dom, $node, 'RedenBroj', (string) $ordinal);
        self::add($dom, $node, 'SifraRabotenOdnos', (string) $employee->insurance_type_code);
        // Двете вистински датотеки носат 1. Не се пренаменува
        // employees.registration_number, кој значи нешто друго.
        self::add($dom, $node, 'BrojDogovor', '1');
        self::add($dom, $node, 'DatumPocetok', self::date($from));
        self::add($dom, $node, 'DatumZavrsuvanje', self::date($to));
        self::add($dom, $node, 'DenoviStaz', (string) $stazDays);
        self::add($dom, $node, 'SifraTipRabotenCas', (string) $line->code);
        self::add($dom, $node, 'SifraDvizenje', (string) $employee->movement_code);
        self::add($dom, $node, 'SifraPodracnoZdravstvo', (string) $employee->health_area_code);
        self::add($dom, $node, 'SifraOsloboduvanje', (string) $employee->exemption_code);
        self::add($dom, $node, 'NadlezenOrganEdb', '');
        // hours е nullable (ставки со износ, на пр. минат труд, немаат часови)
        // — мора да излезе „0", не празен елемент.
        self::add($dom, $node, 'BrojCasovi', (string) ($line->hours ?? 0));
        self::add($dom, $node, 'BrutoIznos', (string) (int) round($line->amount));

        return $node;
    }

    /**
     * Денот е БЕЗ водечка нула, месецот СО неа: `1.05.2026`. Изгледа како
     * недоследност и не е — така пишува МПИН клиентот.
     */
    private static function date(CarbonImmutable $date): string
    {
        return $date->format('j.m.Y');
    }

    private static function add(DOMDocument $dom, DOMElement $parent, string $name, string $value): void
    {
        $parent->appendChild($dom->createElement($name, $value));
    }
}
