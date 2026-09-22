<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/**
 * Кои апликации му се отворени на овој човек за оваа фирма. Го користат и
 * панелот „АПЛИКАЦИИ“ и плочките на порталот, па правилото живее на едно место.
 *
 * Апликација без ниту еден екран не се прикажува — плочка што води кон 403 е
 * полоша од отсутна плочка.
 */
class AppSwitcher
{
    /** @return list<array{key: string, label: string, url: string, accent: string}> */
    public static function for(User $user, ?Company $company): array
    {
        if ($company === null) {
            return [];
        }

        $apps = [];

        foreach (PortalApp::workApps() as $app) {
            if (! $user->canAccessApp($app)) {
                continue;
            }

            $url = Menu::landingUrl($user, $company, $app);

            if ($url === null) {
                continue;
            }

            $apps[] = [
                'key' => $app->value,
                'label' => $app->label(),
                'url' => $url,
                'accent' => $app->accent(),
            ];
        }

        return $apps;
    }
}
