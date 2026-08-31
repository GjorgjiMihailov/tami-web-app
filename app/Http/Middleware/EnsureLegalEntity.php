<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Екраните што важат само за фирма не се достапни на профил на физичко лице.
 *
 * Ова е вистинската брана. Криењето во менито само спречува кликање — без ова,
 * секој од тие екрани останува достапен со впишување адреса или со стар
 * обележувач. Истата дупка беше вистинска за улогата клиент пред да се затвори
 * со `EnsureAccountingAccess`, и беше затворена дури откако тест ја докажа.
 *
 * Се применува врз цели групи рути, за екран додаден подоцна да биде покриен
 * стандардно наместо со сеќавање.
 */
class EnsureLegalEntity
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->route('company');

        abort_if(
            $company instanceof Company && $company->type->isIndividual(),
            403,
            'Овој екран важи само за профил на правно лице.'
        );

        return $next($request);
    }
}
