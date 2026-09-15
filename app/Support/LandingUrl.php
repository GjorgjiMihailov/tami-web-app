<?php

namespace App\Support;

use App\Models\User;

/**
 * Каде оди човек што нема барана адреса. Барана адреса (`redirectIntended`)
 * секогаш победува — ова е само резервата.
 *
 * Со точно една видлива фирма (случајот на клиент) најавата на субдомејн
 * останува во таа апликација. Со повеќе фирми нема што да се погоди, па се оди
 * на порталот, каде човекот бира фирма.
 */
class LandingUrl
{
    public static function for(User $user, ?PortalApp $app): string
    {
        if ($app === null || $app === PortalApp::PORTAL) {
            return route('dashboard');
        }

        $companies = $user->visibleCompanies()->limit(2)->get();

        if ($companies->count() !== 1) {
            return route('dashboard');
        }

        return Menu::firstUrl($user, $companies->first(), $app) ?? route('dashboard');
    }
}
