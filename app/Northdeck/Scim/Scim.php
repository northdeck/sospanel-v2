<?php

namespace App\Northdeck\Scim;

use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Northdeck\Sso\Models\SsoConnection;
use App\Northdeck\Sso\Models\SsoGroupMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCIM 2.0 provisioning (RFC 7643/7644), as a preview. Users are people in
 * the workspace; groups map to roles through the SSO connections' group
 * mappings, the same table sign-ins use.
 */
final class Scim
{
    public const string USER = 'urn:ietf:params:scim:schemas:core:2.0:User';

    public const string GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    public const string LIST = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    public const string PATCH = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    public const string ERROR = 'urn:ietf:params:scim:api:messages:2.0:Error';

    public static function error(int $status, string $detail, ?string $scimType = null): JsonResponse
    {
        return self::json(array_filter(['schemas' => [self::ERROR], 'status' => (string) $status, 'detail' => $detail, 'scimType' => $scimType]), $status);
    }

    /** @param  array<string, mixed>  $body */
    public static function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Content-Type' => 'application/scim+json'], JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public static function userResource(ScimUser $scim): array
    {
        $user = $scim->user;
        [$given, $family] = array_pad(explode(' ', (string) $user->name, 2), 2, '');

        return [
            'schemas' => [self::USER],
            'id' => $scim->uuid,
            'externalId' => $scim->external_id,
            'userName' => $scim->user_name,
            'name' => ['formatted' => $user->name, 'givenName' => $given, 'familyName' => $family],
            'displayName' => $user->name,
            'emails' => [['value' => $user->email, 'primary' => true, 'type' => 'work']],
            'active' => $scim->active,
            'groups' => $scim->groups->map(fn (ScimGroup $g) => ['value' => $g->uuid, 'display' => $g->display_name])->values()->all(),
            'meta' => [
                'resourceType' => 'User',
                'created' => $scim->created_at?->toIso8601String(),
                'lastModified' => $scim->updated_at?->toIso8601String(),
                'location' => url('/api/scim/v2/Users/'.$scim->uuid),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function groupResource(ScimGroup $group): array
    {
        return [
            'schemas' => [self::GROUP],
            'id' => $group->uuid,
            'externalId' => $group->external_id,
            'displayName' => $group->display_name,
            'members' => $group->members()->get()->map(fn (ScimUser $u) => ['value' => $u->uuid, 'display' => $u->user_name])->values()->all(),
            'meta' => [
                'resourceType' => 'Group',
                'created' => $group->created_at?->toIso8601String(),
                'lastModified' => $group->updated_at?->toIso8601String(),
                'location' => url('/api/scim/v2/Groups/'.$group->uuid),
            ],
        ];
    }

    /** @param  array<string, mixed>  $body */
    public static function emailOf(array $body): string
    {
        foreach ((array) ($body['emails'] ?? []) as $email) {
            if (is_array($email) && ($email['primary'] ?? false) && is_string($email['value'] ?? null)) {
                return Str::lower(trim($email['value']));
            }
        }
        $first = ((array) ($body['emails'] ?? []))[0]['value'] ?? null;
        $fallback = is_string($first) ? $first : (string) ($body['userName'] ?? '');

        return Str::lower(trim($fallback));
    }

    /** @param  array<string, mixed>  $body */
    public static function nameOf(array $body, string $email): string
    {
        $name = $body['name'] ?? [];
        $formatted = is_array($name) ? trim((string) ($name['formatted'] ?? trim(((string) ($name['givenName'] ?? '')).' '.((string) ($name['familyName'] ?? ''))))) : '';

        return $formatted !== '' ? $formatted : (is_string($body['displayName'] ?? null) && $body['displayName'] !== '' ? $body['displayName'] : Str::before($email, '@'));
    }

    public static function domainVerified(Workspace $workspace, string $email): bool
    {
        return $workspace->emailDomains()->whereNotNull('verified_at')->where('domain', Str::lower(Str::after($email, '@')))->exists();
    }

    /** Joins, or leaves, the workspace to match `active`. */
    public static function applyActive(Workspace $workspace, ScimUser $scim, bool $active, string $reason = 'deactivated'): void
    {
        $user = $scim->user;
        $member = $workspace->hasMember($user);

        if ($active && ! $member) {
            $role = self::roleFor($workspace, $scim) ?? WorkspaceRole::Member;
            $workspace->members()->attach($user, ['role' => $role->value]);
            Activity::system($workspace, 'member.join', $user->name, meta: ['via' => 'scim', 'role' => $role->label()]);

            return;
        }

        if (! $active && $member) {
            if ($workspace->owner_id === $user->id) {
                Activity::system($workspace, 'scim.owner_kept', $user->name);

                return;
            }
            $workspace->members()->detach($user->id);
            $signedOut = false;
            if (! $user->workspaces()->exists() && config('session.driver') === 'database') {
                $signedOut = DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete() > 0;
            }
            Activity::system($workspace, 'member.remove', $user->name, meta: ['via' => 'scim', 'reason' => $reason, 'signed_out' => $signedOut]);
        }
    }

    /** The highest role any of the person's SCIM groups maps to. */
    public static function roleFor(Workspace $workspace, ScimUser $scim): ?WorkspaceRole
    {
        $names = $scim->groups()->get()->flatMap(fn (ScimGroup $g) => array_filter([$g->display_name, $g->external_id]))->unique()->values()->all();
        if ($names === []) {
            return null;
        }

        $order = ['admin' => 3, 'member' => 2, 'viewer' => 1];
        $roles = SsoGroupMapping::query()
            ->whereIn('connection_id', SsoConnection::query()->where('workspace_id', $workspace->id)->select('id'))
            ->whereIn('group', $names)
            ->pluck('role')
            ->sortByDesc(fn (string $role) => $order[$role] ?? 0);

        return WorkspaceRole::tryFrom((string) $roles->first());
    }

    public static function syncRole(Workspace $workspace, ScimUser $scim): void
    {
        $user = $scim->user;
        $current = $workspace->roleOf($user);
        $mapped = self::roleFor($workspace, $scim);
        if ($current === null || $mapped === null || $current === WorkspaceRole::Owner || $current === $mapped) {
            return;
        }

        $workspace->members()->updateExistingPivot($user->id, ['role' => $mapped->value, 'custom_role_id' => null]);
        Activity::system($workspace, 'member.role', $user->name, meta: ['via' => 'sso_sync', 'role' => $mapped->label(), 'from' => $current->label()]);
    }

    public static function findOrCreateUser(string $email, string $name): User
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();
        if ($user !== null) {
            return $user;
        }

        $user = User::create(['name' => Str::limit($name, 255, ''), 'email' => $email, 'password' => Str::random(64)]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }
}
