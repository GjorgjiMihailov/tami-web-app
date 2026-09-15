<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Menu;
use App\Support\PortalApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Браната пред цела апликација. Апликацијата доаѓа како параметар на групата, не
 * од хостот — така не може да се измами преку адресата.
 *
 * Два клуча: правото на човекот (`users.app_*`) и тоа дали апликацијата воопшто
 * има што да покаже за оваа фирма. Второто се чита од менито, кое веќе ги знае
 * модулот, улогата и типот на клиент.
 */
class EnsureAppAccess
{
    public function handle(Request $request, Closure $next, string $app): Response
    {
        $portalApp = PortalApp::from($app);
        $user = $request->user();
        $company = $request->route('company');

        if ($user === null) {
            return $next($request);
        }

        if (! $user->canAccessApp($portalApp)) {
            return $this->denied($portalApp, 'Немате пристап до оваа апликација.');
        }

        if ($company instanceof Company && Menu::for($user, $company, $portalApp) === []) {
            return $this->denied($portalApp, 'Овој модул не е вклучен за оваа фирма.');
        }

        return $next($request);
    }

    private function denied(PortalApp $app, string $message): Response
    {
        return response()->view('errors.app-access', [
            'app' => $app,
            'message' => $message,
        ], 403);
    }
}
