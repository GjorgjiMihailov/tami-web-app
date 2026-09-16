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
     * Насловот на прозорецот. Напишан цел по апликација наместо составен од
     * делови — сопственикот ги диктира точните низи, а порталот не го следи
     * обликот „дел | ТАМИ" на другите три.
     */
    public function title(): string
    {
        return match ($this) {
            self::PORTAL => 'ТАМИ - FinanceBuddy App',
            self::PRODAZBA => 'Продажба | ТАМИ',
            self::FINANSII => 'Финансии | ТАМИ',
            self::PLATA => 'Плати | ТАМИ',
        };
    }

    /**
     * Првиот ред од главата на сајдбарот — со големи букви, зашто кажува каде
     * си. „Плати", не „Плата", по истиот избор како насловот на прозорецот.
     */
    public function sidebarName(): string
    {
        return match ($this) {
            self::PORTAL => 'ТАМИ',
            self::PRODAZBA => 'ПРОДАЖБА',
            self::FINANSII => 'ФИНАНСИИ',
            self::PLATA => 'ПЛАТИ',
        };
    }

    /**
     * Вториот ред, во курзив. На порталот „ТАМИ" веќе стои во првиот ред, па
     * не се повторува.
     */
    public function sidebarTagline(): string
    {
        return $this === self::PORTAL
            ? 'FinanceBuddy App'
            : 'ТАМИ - FinanceBuddy App';
    }

    /**
     * Апликацијата на која се наоѓа тековното барање. Порталот е резервата —
     * рутите без домен (најава, Livewire) се фаќаат на секој хост, па и тогаш
     * мора да има што да се прикаже.
     */
    public static function current(): self
    {
        return self::fromHost(request()->getHost()) ?? self::PORTAL;
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
