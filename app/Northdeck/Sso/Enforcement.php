<?php

namespace App\Northdeck\Sso;

use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Northdeck\Sso\Models\SsoConnection;
use Illuminate\Http\Request;

/**
 * "Require SSO": members of the workspace sign in with one of its
 * connections. Owners and instance admins with two-factor on can still use a
 * password (break-glass), so a broken identity provider never locks a
 * workspace out. Every break-glass entry is audited.
 */
final class Enforcement
{
    public static function requires(Workspace $workspace): bool
    {
        return (bool) $workspace->require_sso
            && $workspace->hasSso()
            && SsoConnection::query()->where('workspace_id', $workspace->id)->where('enabled', true)->exists();
    }

    public static function satisfiedBy(Request $request, Workspace $workspace, User $user): bool
    {
        $marker = $request->hasSession() ? $request->session()->get(SsoSignIn::SESSION) : null;
        if (! is_array($marker) || ($marker['user_id'] ?? null) !== $user->id || ! is_string($marker['connection'] ?? null)) {
            return false;
        }

        $connection = SsoConnection::query()->where('uuid', $marker['connection'])->first();

        return $connection !== null && $connection->enabled
            && ($connection->workspace_id === $workspace->id || ($marker['workspace_id'] ?? null) === $workspace->id);
    }

    public static function canBreakGlass(Workspace $workspace, User $user): bool
    {
        return ($workspace->owner_id === $user->id || $user->is_admin)
            && $user->two_factor_confirmed_at !== null;
    }

    /** The first workspace that would stop this person using a password. */
    public static function blockingWorkspace(User $user): ?Workspace
    {
        return $user->workspaces()
            ->where('require_sso', true)
            ->get()
            ->first(fn (Workspace $workspace) => self::requires($workspace) && ! self::canBreakGlass($workspace, $user));
    }

    public static function recordBreakGlass(Request $request, Workspace $workspace, User $user): void
    {
        $key = 'northdeck_sso_break_glass.'.$workspace->id;
        if ($request->hasSession() && $request->session()->get($key) === true) {
            return;
        }

        Activity::record($workspace, 'sso.break_glass', user: $user, meta: ['admin' => $user->is_admin && $workspace->owner_id !== $user->id]);
        if ($request->hasSession()) {
            $request->session()->put($key, true);
        }
    }
}
