<?php

namespace App\Northdeck\Sso;

use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Listeners\RecordAccountEvents;
use App\Models\AccountEvent;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Northdeck\Admin\SignUpPolicy;
use App\Northdeck\Roles\RoleMapping;
use App\Northdeck\Sso\Models\SsoConnection;
use App\Northdeck\Sso\Models\SsoGroupMapping;
use App\Northdeck\Sso\Models\SsoIdentity;
use App\Support\Extensions\Extensions;
use App\Support\Workspaces\RoleChoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * Turns checked claims into a signed-in person.
 *
 * Linking rules: an identity is keyed on (connection, subject), never on the
 * email. A new identity is linked to an existing account only when the
 * provider vouches for the email and the email is on one of the connection's
 * verified domains.
 */
final class SsoSignIn
{
    public const string SESSION = 'northdeck_sso';

    public function __construct(private readonly CreateWorkspace $createWorkspace) {}

    /**
     * What a sign-in with these claims would do, without doing it. The test
     * button shows this.
     *
     * @return array{action: 'sign_in'|'link'|'create'|'refuse', reason: string|null, user_id: int|null, role: string|null}
     */
    public function plan(SsoConnection $connection, Claims $claims): array
    {
        $refuse = fn (string $reason) => ['action' => 'refuse', 'reason' => $reason, 'user_id' => null, 'role' => null];

        if (! $connection->enabled) {
            return $refuse('This connection is turned off.');
        }

        $identity = SsoIdentity::query()->where('connection_id', $connection->id)->where('subject', $claims->subject)->first();
        $workspace = $this->workspaceOf($connection);
        $role = $workspace === null ? null : $this->mappedRole($workspace, $connection, $claims->groups);
        $role = $role === null ? null : RoleMapping::value($role);

        if ($identity !== null) {
            return $identity->user->suspended_at !== null
                ? $refuse('This account has been suspended.')
                : ['action' => 'sign_in', 'reason' => null, 'user_id' => $identity->user_id, 'role' => $role];
        }

        if ($claims->email === '' || filter_var($claims->email, FILTER_VALIDATE_EMAIL) === false) {
            return $refuse('The identity provider didn’t send an email address.');
        }
        if (! $connection->coversEmail($claims->email)) {
            return $refuse('Emails on '.Str::after($claims->email, '@').' can’t sign in with this connection. Verify the domain and add it to the connection.');
        }
        if (! $claims->emailVerified) {
            return $refuse('The identity provider didn’t confirm this email address, so it can’t be linked to an account.');
        }

        $existing = User::query()->whereRaw('lower(email) = ?', [$claims->email])->orderBy('id')->first();
        if ($existing !== null) {
            return match (true) {
                $existing->suspended_at !== null => $refuse('This account has been suspended.'),
                $existing->email_verified_at === null => $refuse('There’s an account with this email that was never confirmed. Confirm it first, then sign in with SSO.'),
                default => ['action' => 'link', 'reason' => null, 'user_id' => $existing->id, 'role' => $role],
            };
        }

        if (! $connection->jit) {
            return $refuse('There’s no account for '.$claims->email.' yet. Ask a workspace admin to invite you.');
        }
        if (! SignUpPolicy::ssoMayCreate($claims->email)) {
            return $refuse('There’s no account for '.$claims->email.', and this panel doesn’t make new ones through SSO. Ask its admin for an invite.');
        }

        return ['action' => 'create', 'reason' => null, 'user_id' => null, 'role' => $role ?? $connection->default_role];
    }

    public function signIn(Request $request, SsoConnection $connection, Claims $claims): RedirectResponse
    {
        $plan = $this->plan($connection, $claims);
        $workspace = $this->workspaceOf($connection);

        if ($plan['action'] === 'refuse') {
            $this->failed($connection, (string) $plan['reason'], $claims->email);

            throw new SsoException((string) $plan['reason']);
        }

        $user = DB::transaction(function () use ($connection, $claims, $plan, $workspace): User {
            $user = match ($plan['action']) {
                'create' => $this->createUser($claims, $workspace),
                default => User::query()->findOrFail($plan['user_id']),
            };

            $identity = SsoIdentity::query()->firstOrNew(['connection_id' => $connection->id, 'subject' => $claims->subject]);
            $linked = ! $identity->exists;
            $identity->fill(['user_id' => $user->id, 'email' => $claims->email ?: $identity->email, 'last_sign_in_at' => now()])->save();

            if ($linked && $plan['action'] === 'link') {
                AccountEvent::record($user, 'sso.linked', ['connection' => $connection->name, 'workspace' => $workspace->name ?? config('app.name')]);
                if ($workspace !== null) {
                    Activity::record($workspace, 'sso.identity_link', $connection->name, user: $user);
                }
            }

            if ($workspace !== null) {
                $this->syncMembership($workspace, $connection, $user, $claims->groups);
            }

            return $user;
        });

        if ($workspace !== null) {
            Activity::record($workspace, 'sso.sign_in', $connection->name, user: $user, meta: ['email' => $claims->email]);
        }

        $request->session()->put(self::SESSION, [
            'user_id' => $user->id,
            'connection' => $connection->uuid,
            'workspace_id' => $workspace?->id,
            'at' => now()->getTimestamp(),
            'idp' => $claims->session,
        ]);

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => true, RecordAccountEvents::VIA => 'sso']);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $home = $workspace !== null ? $workspace->url() : Fortify::redirects('login');

        return redirect()->intended($home);
    }

    /** @param  list<string>  $groups */
    public function syncMembership(Workspace $workspace, SsoConnection $connection, User $user, array $groups): void
    {
        $mapped = $this->mappedRole($workspace, $connection, $groups);
        $current = $workspace->roleOf($user);

        if ($current === null) {
            if (! $connection->jit) {
                return;
            }
            $role = $mapped ?? RoleMapping::choice($workspace, $connection->default_role) ?? RoleChoice::of(WorkspaceRole::Member, null);
            $workspace->members()->attach($user, RoleMapping::pivot($role));
            Activity::record($workspace, 'member.join', user: $user, meta: ['via' => 'sso', 'role' => $role->label()]);

            return;
        }

        // Owners keep their role; group changes never demote them.
        if ($mapped === null || $current === WorkspaceRole::Owner) {
            return;
        }
        $customId = $workspace->members()->whereKey($user->id)->value('user_workspace.custom_role_id');
        $customId = $customId === null ? null : (int) $customId;
        if ($mapped->matches($current->value, $customId)) {
            return;
        }

        $from = RoleChoice::of($current, $customId === null ? null : $workspace->customRoles()->find($customId))->label();
        $workspace->members()->updateExistingPivot($user->id, RoleMapping::pivot($mapped));
        Activity::record($workspace, 'member.role', $user->name, user: $user, meta: ['via' => 'sso_sync', 'role' => $mapped->label(), 'from' => $from]);
    }

    /**
     * The role with the most access any of the person's groups maps to:
     * a built-in role or one of the workspace's custom roles.
     *
     * @param  list<string>  $groups
     */
    public function mappedRole(Workspace $workspace, SsoConnection $connection, array $groups): ?RoleChoice
    {
        if ($groups === []) {
            return null;
        }

        return RoleMapping::best($workspace, $connection->groupMappings
            ->filter(fn (SsoGroupMapping $m) => in_array($m->group, $groups, true))
            ->map(fn (SsoGroupMapping $m) => $m->role));
    }

    public function workspaceOf(SsoConnection $connection): ?Workspace
    {
        if ($connection->is_instance) {
            $slug = (string) config('northdeck.sso.instance.workspace');

            return $slug === '' ? null : Workspace::query()->where('slug', $slug)->first();
        }

        return $connection->workspace;
    }

    public function failed(SsoConnection $connection, string $reason, string $email = ''): void
    {
        $workspace = $this->workspaceOf($connection);
        if ($workspace !== null) {
            Activity::system($workspace, 'sso.sign_in_failed', $connection->name, meta: array_filter(['reason' => Str::limit($reason, 300), 'email' => $email]));
        } else {
            logger()->warning('SSO sign-in failed', ['connection' => $connection->uuid, 'reason' => $reason]);
        }
    }

    private function createUser(Claims $claims, ?Workspace $workspace): User
    {
        $name = trim($claims->name) !== '' ? $claims->name : Str::before($claims->email, '@');

        $user = User::create([
            'name' => Str::limit($name, 255, ''),
            'email' => $claims->email,
            'password' => Str::random(64),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        app(Extensions::class)->signedUp(request(), $user);

        // Joining a workspace is enough; only someone with nowhere to go gets
        // a workspace of their own, like a normal sign-up.
        if ($workspace === null) {
            $this->createWorkspace->create($user);
        }

        return $user;
    }
}
