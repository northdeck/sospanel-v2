<?php

namespace App\Northdeck\Sso\Http\Middleware;

use App\Models\Workspace;
use App\Northdeck\Sso\Enforcement;
use App\Northdeck\Sso\Support\SsoUrls;
use App\Support\Admin\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inside a workspace that requires SSO, a session that didn't come through
 * one of its connections is sent to sign in with it. Owners and instance
 * admins with two-factor on get through, and that is audited.
 */
final class RequireWorkspaceSso
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');
        $user = $request->user();

        if (! $workspace instanceof Workspace || $user === null || Impersonation::active($request)
            || ! Enforcement::requires($workspace) || Enforcement::satisfiedBy($request, $workspace, $user)) {
            return $next($request);
        }

        if (Enforcement::canBreakGlass($workspace, $user)) {
            Enforcement::recordBreakGlass($request, $workspace, $user);

            return $next($request);
        }

        $url = SsoUrls::route('northdeck.sso.workspace', ['workspace' => $workspace->slug]);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => __(':workspace asks you to sign in with SSO.', ['workspace' => $workspace->name]),
                'code' => 'sso_required',
                'sign_in_url' => $url,
            ], 403);
        }

        return Inertia::render('auth/NorthdeckSsoRequired', [
            'workspace' => $workspace->name,
            'email' => $user->email,
            'signInUrl' => $url,
        ])->toResponse($request);
    }
}
