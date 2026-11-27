<?php

namespace App\Northdeck\Sso;

use Closure;

/**
 * Activity lines for the SSO events upstream has no words for. Called from
 * the one NORTHDECK hook in App\Support\Activity\ActivityLine.
 */
final class ActivityLines
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  Closure(string, ?string=, string=): void  $set
     */
    public static function describe(string $action, array $meta, Closure $set): null
    {
        match ($action) {
            'sso.sign_in' => $set('signed in with', null, 'good'),
            'sso.sign_in_failed' => $set('couldn’t sign someone in with', isset($meta['reason']) && is_string($meta['reason']) ? '('.$meta['reason'].')' : null, 'warn'),
            'sso.break_glass' => $set('got in without SSO', ($meta['admin'] ?? false) ? 'as an instance admin' : 'as the workspace owner', 'warn'),
            default => null,
        };

        return null;
    }
}
