<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CompanyModule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Екраните на модул што фирмата не го користи не се достапни.
 *
 * Ова е вистинската брана. Криењето во менито само спречува кликање — без ова,
 * секој од тие екрани останува достапен со впишување адреса или со стар
 * обележувач. Истата дупка беше вистинска за улогата клиент пред да се затвори
 * со `EnsureAccountingAccess`, и за физичко лице пред `EnsureLegalEntity`.
 *
 * Се применува врз цели групи рути, за екран додаден подоцна да биде покриен
 * стандардно наместо со сеќавање.
 */
class EnsureCompanyModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $company = $request->route('company');

        abort_if(
            $company instanceof Company && ! $company->usesModule(CompanyModule::from($module)),
            403,
            'Овој модул не е вклучен за оваа фирма.'
        );

        return $next($request);
    }
}
