<?php

namespace App\Northdeck\Scim;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A workspace's SCIM bearer token; the token says which workspace. */
final class AuthenticateScim
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken();
        $token = $secret === null ? null : ScimToken::findBySecret($secret);

        if ($token === null || $token->workspace === null || ! $token->workspace->hasScim()) {
            return Scim::error(401, 'Invalid or missing SCIM token.');
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        $request->attributes->set('scim_workspace', $token->workspace);

        return $next($request);
    }
}
