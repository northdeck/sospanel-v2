<?php

namespace App\Northdeck\Admin\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Northdeck's /admin pages are gone when NORTHDECK_ADMIN is off. */
final class EnsureAdminPages
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('northdeck.admin.enabled'), 404);

        return $next($request);
    }
}
