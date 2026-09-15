<?php

namespace App\Support;

/**
 * Кои апликации постојат. Единственото место што ги знае имињата, домените и
 * колоната со правото — рутите, менито, панелот и браната сите читаат од тука.
 */
enum PortalApp: string
{
    case PORTAL = 'portal';
    case PRODAZBA = 'prodazba';
    case FINANSII = 'finansii';
    case PLATA = 'plata';

    public function domain(): string
    {
        return (string) config("apps.domains.{$this->value}");
    }

    public function label(): string
    {
        return match ($this) {
            self::PORTAL => 'Портал',
            self::PRODAZBA => 'Продажба',
            self::FINANSII => 'Финансии',
            self::PLATA => 'Плата',
        };
    }

    /**
     * Колоната на `users` што го носи правото. Порталот нема — најавен корисник
     * секогаш смее да влезе во порталот, инаку не би имал каде да отиде.
     */
    public function userColumn(): ?string
    {
        return $this === self::PORTAL ? null : 'app_'.$this->value;
    }

    /**
     * Акцентната боја. Класите се испишани цели зашто Tailwind JIT чита текст,
     * не составува имиња на класи во време на извршување.
     */
    public function accent(): string
    {
        return match ($this) {
            self::PRODAZBA => 'text-brand',
            self::FINANSII => 'text-emerald-700',
            self::PLATA => 'text-indigo-700',
            self::PORTAL => 'text-brand',
        };
    }

    public static function fromHost(?string $host): ?self
    {
        foreach (self::cases() as $app) {
            if ($host !== null && $app->domain() === $host) {
                return $app;
            }
        }

        return null;
    }

    /** @return list<self> */
    public static function workApps(): array
    {
        return [self::PRODAZBA, self::FINANSII, self::PLATA];
    }
}
