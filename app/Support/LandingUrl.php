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
        // Админот не бира фирма и не влегува во апликација при најава: има
        // сопствено табло. И кога системот има само една фирма.
        if ($app === null || $app === PortalApp::PORTAL || $user->hasRole('admin')) {
            return route('dashboard');
        }

        // Право на апликацијата се проверува пред менито. Без ова, човек без
        // право за таа апликација би бил пратен право во 403 екранот наместо
        // на порталот — менито не го знае правото, само содржината.
        if (! $user->canAccessApp($app)) {
            return route('dashboard');
        }

        $companies = $user->visibleCompanies()->limit(2)->get();

        if ($companies->count() !== 1) {
            return route('dashboard');
        }

        return Menu::landingUrl($user, $companies->first(), $app) ?? route('dashboard');
    }
}
