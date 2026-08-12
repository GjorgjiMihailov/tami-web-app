<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bookkeeping screens are for the firm, not for the client whose books
 * they are.
 *
 * This is the real gate. Removing ФИНАНСИИ from the client's menu only stops
 * them clicking through — without this, every accounting screen stays
 * reachable by typing the URL or following an old bookmark.
 *
 * Applied to whole route groups rather than to each component, so a screen
 * added later is covered by default instead of by remembering.
 */
class EnsureAccountingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user()?->hasAnyRole(['admin', 'accountant']),
            403,
            'Сметководствените екрани се достапни само за администратор и сметководител.'
        );

        return $next($request);
    }
}
