<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Огледалото на `EnsureLegalEntity`: екраните што важат само за физичко лице не
 * се достапни на профил на правно лице.
 *
 * 743 образецот е потврда за приход од странство на човек, не на фирма. Без ова
 * секој од тие екрани останува достапен со впишување адреса, како што веќе
 * двапати се покажа во оваа апликација.
 */
class EnsureIndividual
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->route('company');

        abort_if(
            $company instanceof Company && $company->type->isLegal(),
            403,
            'Овој екран важи само за профил на физичко лице.'
        );

        return $next($request);
    }
}
