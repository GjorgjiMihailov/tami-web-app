<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Исклучувањето важи и за сесија што била отворена пред него. Без ова,
 * корисник кому му е одземен пристапот работи натаму сѐ додека не се одјави сам.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->disabled_at !== null) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['form.email' => 'Пристапот за оваа сметка е исклучен.']);
        }

        return $next($request);
    }
}
