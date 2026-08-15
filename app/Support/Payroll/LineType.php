<?php

namespace App\Support\Payroll;

use App\Models\PayrollRunLine;

/**
 * The subset of УЈП's codebooks the line editor offers, and what the app
 * assumes about each code.
 *
 * All 60 rab_cas plus 17 vid_nadomestoci codes live in `payroll_codes` because
 * 5c needs them. This list is the shortlist a private-sector payroll actually
 * uses. Extending it is an edit here, not a migration.
 */
final class LineType
{
    /**
     * The codes that make up the base for the seniority bonus. Deliberately an
     * explicit list rather than a rule like "ordinary hours and paid absence":
     * a rule invites argument about sick leave, which is already a percentage
     * of the salary and must not be uplifted a second time.
     *
     * @var list<string>
     */
    public const BASE_CODES = ['001', '009', '010', '012'];

    /**
     * Codes whose cost a state body carries, not the employer. The company
     * still calculates and declares them — they simply never reach its ledger.
     *
     * @var list<string>
     */
    public const FZO_CODES = ['129', '132', '138', '139'];

    /** The seniority bonus, half a percent per completed year. */
    public const SENIORITY_CODE = '037';

    public const SENIORITY_PERCENT_PER_YEAR = 0.5;

    /**
     * code => [label, percent, kind]
     *
     * @var array<string, array{label: string, percent: float, kind: string}>
     */
    public const OFFERED = [
        '001' => ['label' => 'Редовни работни часови', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '009' => ['label' => 'Годишен одмор', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '010' => ['label' => 'Државен празник', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '012' => ['label' => 'Платено отсуство', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '003' => ['label' => 'Ноќна работа', 'percent' => 135.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '005' => ['label' => 'Прекувремена работа', 'percent' => 135.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '007' => ['label' => 'Работа на државен празник', 'percent' => 150.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '023' => ['label' => 'Неплатено отсуство', 'percent' => 0.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '125' => ['label' => 'Боледување 70%', 'percent' => 70.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '126' => ['label' => 'Боледување 80%', 'percent' => 80.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '127' => ['label' => 'Боледување 90%', 'percent' => 90.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '128' => ['label' => 'Боледување 100%', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '129' => ['label' => 'Боледување на товар на ФЗО', 'percent' => 70.0, 'kind' => PayrollRunLine::KIND_HOURS],
        '037' => ['label' => 'Минат труд', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_AMOUNT],
        '029' => ['label' => 'Храна', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_AMOUNT],
        '030' => ['label' => 'Превоз', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_AMOUNT],
        '034' => ['label' => 'Награда', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_AMOUNT],
        '062' => ['label' => 'Бонус за успешност', 'percent' => 100.0, 'kind' => PayrollRunLine::KIND_AMOUNT],
    ];

    public static function borneBy(?string $code): string
    {
        return $code !== null && in_array($code, self::FZO_CODES, true)
            ? PayrollRunLine::BORNE_FZO
            : PayrollRunLine::BORNE_EMPLOYER;
    }

    public static function defaultPercent(string $code): float
    {
        return self::OFFERED[$code]['percent'] ?? 100.0;
    }

    public static function label(string $code): string
    {
        return self::OFFERED[$code]['label'] ?? $code;
    }

    public static function isBase(?string $code): bool
    {
        return $code !== null && in_array($code, self::BASE_CODES, true);
    }
}
