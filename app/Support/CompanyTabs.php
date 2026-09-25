<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/**
 * Картичките на профилот на клиент. Држени како податок, по истата причина
 * како App\Support\Menu — за да може табелата на улоги да се тврди директно во
 * тест, наместо со чепкање низ исцртан HTML.
 *
 * Никогаш не вика request(). Тековната рута ја дознава преку аргументот.
 */
class CompanyTabs
{
    /**
     * @return list<array{label: string, url: string, active: bool}>
     */
    public static function for(User $user, Company $company, ?string $currentRoute = null): array
    {
        $tabs = [
            ['label' => 'Профил', 'route' => 'companies.profile', 'roles' => null, 'legalOnly' => false],
            ['label' => 'Модули', 'route' => 'companies.modules', 'roles' => ['admin'], 'legalOnly' => true],
            ['label' => 'Корисници', 'route' => 'companies.users', 'roles' => null, 'legalOnly' => false],
        ];

        $visible = [];

        foreach ($tabs as $tab) {
            if ($tab['roles'] !== null && ! $user->hasAnyRole($tab['roles'])) {
                continue;
            }

            // Модулите важат само за правно лице — исто како во менито.
            if ($tab['legalOnly'] && $company->type->isIndividual()) {
                continue;
            }

            $visible[] = [
                'label' => $tab['label'],
                'url' => route($tab['route'], $company),
                'active' => $currentRoute === $tab['route'],
            ];
        }

        return $visible;
    }
}
