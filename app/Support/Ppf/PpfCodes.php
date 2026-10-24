<?php

namespace App\Support\Ppf;

use Carbon\CarbonInterface;

/**
 * Шифрарниците на УЈП за аконтативна пресметка (ППФ): вид и подвид на приход,
 * начин на плаќање. Изворот се XLS-фајловите на УЈП, претворени во JSON во
 * database/data/ppf. Општините се во payroll_codes (тип opstina).
 */
final class PpfCodes
{
    /** Стар шифрарник (видови 1–19): важи само за исплати во 2018. */
    private const LEGACY_YEAR = 2018;

    /** Новиот шифрарник (T1…T11) важи од оваа година; вистинските фајлови од 2024–26 го користат. */
    private const CURRENT_FROM_YEAR = 2019;

    /** @var array<string, list<array<string, mixed>>> */
    private static array $cache = [];

    /** @return list<array{code: string, name: string, legacy: bool}> */
    public static function types(): array
    {
        return self::load('type_income');
    }

    /** @return list<array{code: string, name: string, type: string}> */
    public static function subtypes(?string $type = null): array
    {
        $all = self::load('subtype_income');

        return $type === null ? $all : array_values(array_filter($all, fn (array $s) => $s['type'] === $type));
    }

    /** @return list<array{code: string, name: string}> */
    public static function paymentTypes(): array
    {
        return self::load('type_payment');
    }

    public static function typeExists(string $code): bool
    {
        return self::find(self::types(), $code) !== null;
    }

    public static function subtypeBelongsTo(string $type, string $subtype): bool
    {
        $found = self::find(self::subtypes(), $subtype);

        return $found !== null && $found['type'] === $type;
    }

    public static function paymentTypeExists(string $code): bool
    {
        return self::find(self::paymentTypes(), $code) !== null;
    }

    public static function typeHasSubtypes(string $type): bool
    {
        return self::subtypes($type) !== [];
    }

    public static function typeName(string $code): ?string
    {
        return self::find(self::types(), $code)['name'] ?? null;
    }

    public static function subtypeName(string $code): ?string
    {
        return self::find(self::subtypes(), $code)['name'] ?? null;
    }

    public static function isLegacy(string $type): bool
    {
        return (self::find(self::types(), $type)['legacy'] ?? false) === true;
    }

    /** Дали видот се користи за исплата на тој датум (стар шифрарник — 2018, нов — од 2019). */
    public static function typeValidOn(string $type, CarbonInterface $date): bool
    {
        if (! self::typeExists($type)) {
            return false;
        }

        return self::isLegacy($type)
            ? $date->year === self::LEGACY_YEAR
            : $date->year >= self::CURRENT_FROM_YEAR;
    }

    /** Видовите што се нудат за датум; без датум — само тековниот шифрарник. @return list<array<string, mixed>> */
    public static function typesFor(?CarbonInterface $date): array
    {
        return array_values(array_filter(self::types(), fn (array $t) => $date === null
            ? ! $t['legacy']
            : self::typeValidOn($t['code'], $date)));
    }

    /** @param list<array<string, mixed>> $list */
    private static function find(array $list, string $code): ?array
    {
        foreach ($list as $row) {
            if ($row['code'] === $code) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private static function load(string $name): array
    {
        return self::$cache[$name] ??= json_decode(
            (string) file_get_contents(database_path("data/ppf/{$name}.json")),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
