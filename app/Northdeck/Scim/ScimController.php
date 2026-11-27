<?php

namespace App\Northdeck\Scim;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCIM 2.0 at /api/scim/v2. Supports what Okta, Entra ID, OneLogin and
 * JumpCloud send: Users and Groups with list (filter `eq`), get, create,
 * replace, patch and delete.
 */
final class ScimController extends Controller
{
    public function serviceProviderConfig(): JsonResponse
    {
        return Scim::json([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => 200],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'Bearer token', 'description' => 'The SCIM token from Settings › Single sign-on.', 'primary' => true]],
        ]);
    }

    public function resourceTypes(): JsonResponse
    {
        $type = fn (string $name, string $endpoint, string $schema) => ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'], 'id' => $name, 'name' => $name, 'endpoint' => $endpoint, 'schema' => $schema];

        return Scim::json(['schemas' => [Scim::LIST], 'totalResults' => 2, 'Resources' => [$type('User', '/Users', Scim::USER), $type('Group', '/Groups', Scim::GROUP)]]);
    }

    public function schemas(): JsonResponse
    {
        return Scim::json(['schemas' => [Scim::LIST], 'totalResults' => 2, 'Resources' => [
            ['id' => Scim::USER, 'name' => 'User', 'attributes' => []],
            ['id' => Scim::GROUP, 'name' => 'Group', 'attributes' => []],
        ]]);
    }

    public function users(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $query = ScimUser::query()->where('workspace_id', $workspace->id)->with(['user', 'groups']);

        if (($filter = $this->filter($request)) !== null) {
            [$attribute, $value] = $filter;
            match (strtolower($attribute)) {
                'username' => $query->whereRaw('lower(user_name) = ?', [Str::lower($value)]),
                'externalid' => $query->where('external_id', $value),
                'emails.value', 'emails' => $query->whereHas('user', fn ($q) => $q->whereRaw('lower(email) = ?', [Str::lower($value)])),
                default => $query->whereRaw('1 = 0'),
            };
        }

        return $this->list($request, $query->orderBy('id'), fn (ScimUser $u) => Scim::userResource($u));
    }

    public function showUser(Request $request, string $id): JsonResponse
    {
        $scim = $this->findUser($request, $id);

        return $scim === null ? Scim::error(404, 'No such user.') : Scim::json(Scim::userResource($scim));
    }

    public function createUser(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $body = $request->json()->all();
        $email = Scim::emailOf($body);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return Scim::error(400, 'A user needs an email address.', 'invalidValue');
        }
        if (! Scim::domainVerified($workspace, $email)) {
            return Scim::error(400, 'Emails on '.Str::after($email, '@').' aren’t on a verified domain of this workspace.', 'invalidValue');
        }

        $userName = (string) ($body['userName'] ?? $email);
        if (ScimUser::query()->where('workspace_id', $workspace->id)->whereRaw('lower(user_name) = ?', [Str::lower($userName)])->exists()) {
            return Scim::error(409, 'That userName is already provisioned.', 'uniqueness');
        }

        $scim = DB::transaction(function () use ($workspace, $body, $email, $userName): ScimUser {
            $user = Scim::findOrCreateUser($email, Scim::nameOf($body, $email));
            $linked = ! $user->wasRecentlyCreated && $workspace->hasMember($user);

            $scim = ScimUser::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'user_id' => $user->id],
                ['user_name' => $userName, 'external_id' => $body['externalId'] ?? null, 'active' => (bool) ($body['active'] ?? true)],
            );
            if ($linked) {
                Activity::system($workspace, 'scim.user_link', $user->name);
            }
            Scim::applyActive($workspace, $scim, $scim->active);

            return $scim;
        });

        return Scim::json(Scim::userResource($scim->load(['user', 'groups'])), 201);
    }

    public function replaceUser(Request $request, string $id): JsonResponse
    {
        $scim = $this->findUser($request, $id);
        if ($scim === null) {
            return Scim::error(404, 'No such user.');
        }

        $this->updateUser($this->workspace($request), $scim, $request->json()->all());

        return Scim::json(Scim::userResource($scim->refresh()->load(['user', 'groups'])));
    }

    public function patchUser(Request $request, string $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $scim = $this->findUser($request, $id);
        if ($scim === null) {
            return Scim::error(404, 'No such user.');
        }

        // Check every operation first, so a bad one changes nothing.
        $changes = [];
        $removeExternalId = false;
        foreach ($this->operations($request) as [$op, $path, $value]) {
            if ($op === 'remove') {
                $attribute = $path === null ? null : PatchPath::userAttribute($path);
                if ($attribute === null) {
                    return Scim::error(400, 'remove needs a path to a user attribute.', $path === null ? 'noTarget' : 'invalidPath');
                }
                if ($attribute === 'externalId') {
                    $removeExternalId = true;
                } elseif (in_array($attribute, PatchPath::IGNORED_USER, true)) {
                    // Not stored here, so there's nothing to remove.
                } else {
                    return Scim::error(400, "{$attribute} can't be removed from a user.", 'mutability');
                }

                continue;
            }

            if ($path === null) {
                if (! is_array($value) || array_is_list($value)) {
                    return Scim::error(400, "{$op} without a path needs an object of attributes.", 'invalidValue');
                }
                foreach ($value as $key => $v) {
                    $attribute = PatchPath::userAttribute((string) $key);
                    if ($attribute === null) {
                        return Scim::error(400, "Unknown user attribute: {$key}.", 'invalidPath');
                    }
                    if (! in_array($attribute, PatchPath::IGNORED_USER, true)) {
                        // Entra sends {"name.givenName": "Ada"}; Okta sends {"name": {...}}.
                        data_set($changes, PatchPath::strip((string) $key), $v);
                    }
                }

                continue;
            }

            $attribute = PatchPath::userAttribute($path);
            if ($attribute === null) {
                return Scim::error(400, "Unknown user attribute: {$path}.", 'invalidPath');
            }
            if ($attribute === 'emails') {
                // emails[type eq "work"].value and the like: only used to log
                // that the sign-in email is kept (see updateUser).
                $changes['emails'] = is_array($value) ? $value : [['value' => $value, 'primary' => true]];
            } elseif (! in_array($attribute, PatchPath::IGNORED_USER, true)) {
                data_set($changes, PatchPath::strip($path), $value);
            }
        }

        // A partial name (just givenName, say) keeps the other half.
        if (is_array($changes['name'] ?? null) && ! isset($changes['name']['formatted'])) {
            [$given, $family] = array_pad(explode(' ', (string) $scim->user->name, 2), 2, '');
            $changes['name'] += ['givenName' => $given, 'familyName' => $family];
        }

        if ($removeExternalId) {
            $scim->forceFill(['external_id' => null])->save();
        }
        $this->updateUser($workspace, $scim, $changes);

        return Scim::json(Scim::userResource($scim->refresh()->load(['user', 'groups'])));
    }

    public function deleteUser(Request $request, string $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $scim = $this->findUser($request, $id);
        if ($scim === null) {
            return Scim::error(404, 'No such user.');
        }

        Scim::applyActive($workspace, $scim, false, 'deleted');
        Activity::system($workspace, 'scim.user_delete', $scim->user->name);
        $scim->delete();

        return response()->json(null, 204);
    }

    public function groups(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $query = ScimGroup::query()->where('workspace_id', $workspace->id);

        if (($filter = $this->filter($request)) !== null) {
            [$attribute, $value] = $filter;
            match (strtolower($attribute)) {
                'displayname' => $query->where('display_name', $value),
                'externalid' => $query->where('external_id', $value),
                default => $query->whereRaw('1 = 0'),
            };
        }

        return $this->list($request, $query->orderBy('id'), fn (ScimGroup $g) => Scim::groupResource($g));
    }

    public function showGroup(Request $request, string $id): JsonResponse
    {
        $group = $this->findGroup($request, $id);

        return $group === null ? Scim::error(404, 'No such group.') : Scim::json(Scim::groupResource($group));
    }

    public function createGroup(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $name = trim((string) $request->json('displayName', ''));
        if ($name === '') {
            return Scim::error(400, 'A group needs a displayName.', 'invalidValue');
        }

        $group = ScimGroup::query()->create(['workspace_id' => $workspace->id, 'display_name' => $name, 'external_id' => $request->json('externalId')]);
        $ids = $this->memberIds($workspace, (array) $request->json('members', []));
        $group->members()->sync($ids);
        Activity::system($workspace, 'scim.group_create', $name, meta: ['members' => count($ids)]);
        $this->resyncRoles($workspace, $ids);

        return Scim::json(Scim::groupResource($group), 201);
    }

    public function replaceGroup(Request $request, string $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $group = $this->findGroup($request, $id);
        if ($group === null) {
            return Scim::error(404, 'No such group.');
        }

        $this->rename($workspace, $group, (string) $request->json('displayName', $group->display_name));
        $before = $this->currentMembers($group);
        $after = $this->memberIds($workspace, (array) $request->json('members', []));
        $group->members()->sync($after);
        $this->membersChanged($workspace, $group, $before, $after);

        return Scim::json(Scim::groupResource($group->refresh()));
    }

    public function patchGroup(Request $request, string $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $group = $this->findGroup($request, $id);
        if ($group === null) {
            return Scim::error(404, 'No such group.');
        }

        $before = $this->currentMembers($group);
        $members = $before;
        $rename = null;
        $externalId = false;

        // Check every operation first, so a bad one changes nothing.
        foreach ($this->operations($request) as [$op, $path, $value]) {
            $target = strtolower((string) $path);

            if ($path === null) {
                if ($op === 'remove') {
                    return Scim::error(400, 'remove needs a path.', 'noTarget');
                }
                if (! is_array($value) || array_is_list($value)) {
                    return Scim::error(400, "{$op} without a path needs an object of attributes.", 'invalidValue');
                }
                foreach ($value as $key => $v) {
                    $key = strtolower((string) $key);
                    if ($key === 'displayname') {
                        $rename = is_scalar($v) ? (string) $v : '';
                    } elseif ($key === 'externalid') {
                        $externalId = is_string($v) ? $v : null;
                    } elseif ($key === 'members') {
                        $named = $this->memberIds($workspace, is_array($v) ? $v : []);
                        $members = $op === 'add' ? array_values(array_unique([...$members, ...$named])) : $named;
                    } elseif (! in_array($key, ['id', 'schemas', 'meta'], true)) {
                        return Scim::error(400, "Unknown group attribute: {$key}.", 'invalidPath');
                    }
                }

                continue;
            }

            if ($target === 'displayname') {
                if ($op === 'remove') {
                    return Scim::error(400, "A group's displayName can't be removed.", 'mutability');
                }
                $rename = is_scalar($value) ? (string) $value : '';
            } elseif ($target === 'externalid') {
                $externalId = $op === 'remove' ? null : (is_string($value) ? $value : null);
            } elseif (str_starts_with($target, 'members')) {
                $filtered = preg_match('/^members\[value eq "([^"]+)"\]$/i', (string) $path, $m) === 1;
                if (! $filtered && $target !== 'members') {
                    return Scim::error(400, "Unsupported path: {$path}.", 'invalidPath');
                }
                $named = $filtered ? $this->memberIds($workspace, [['value' => $m[1]]]) : $this->memberIds($workspace, is_array($value) ? $value : []);

                $members = match ($op) {
                    // Entra: members[value eq "id"]. Plain "members" with no
                    // value removes everyone, as RFC 7644 says.
                    'remove' => $filtered || is_array($value) ? array_values(array_diff($members, $named)) : [],
                    'add' => array_values(array_unique([...$members, ...$named])),
                    default => $named,
                };
            } else {
                return Scim::error(400, "Unsupported path: {$path}.", $op === 'remove' ? 'noTarget' : 'invalidPath');
            }
        }

        if ($rename !== null) {
            $this->rename($workspace, $group, $rename);
        }
        if ($externalId !== false) {
            $group->forceFill(['external_id' => $externalId])->save();
        }
        $group->members()->sync($members);
        $this->membersChanged($workspace, $group, $before, $members);

        return Scim::json(Scim::groupResource($group->refresh()));
    }

    /**
     * A PatchOp body's operations, normalised. Ops other than add, replace
     * and remove are an error, not something to skip.
     *
     * @return list<array{0: 'add'|'replace'|'remove', 1: string|null, 2: mixed}>
     */
    private function operations(Request $request): array
    {
        $operations = $request->json('Operations');
        if (! is_array($operations) || $operations === []) {
            abort(Scim::error(400, 'A PATCH needs Operations.', 'invalidSyntax'));
        }

        $out = [];
        foreach ($operations as $op) {
            $kind = is_array($op) ? strtolower((string) ($op['op'] ?? '')) : '';
            if (! in_array($kind, ['add', 'replace', 'remove'], true)) {
                abort(Scim::error(400, 'Each operation must be add, replace or remove.', 'invalidSyntax'));
            }
            $path = is_string($op['path'] ?? null) && trim($op['path']) !== '' ? trim($op['path']) : null;
            $out[] = [$kind, $path, $op['value'] ?? null];
        }

        return $out;
    }

    public function deleteGroup(Request $request, string $id): JsonResponse
    {
        $workspace = $this->workspace($request);
        $group = $this->findGroup($request, $id);
        if ($group === null) {
            return Scim::error(404, 'No such group.');
        }

        $members = $group->members()->pluck('northdeck_scim_users.id')->all();
        $group->delete();
        Activity::system($workspace, 'scim.group_delete', $group->display_name);
        $this->resyncRoles($workspace, $members);

        return response()->json(null, 204);
    }

    /** @param  array<string, mixed>  $body */
    private function updateUser(Workspace $workspace, ScimUser $scim, array $body): void
    {
        $user = $scim->user;

        if (isset($body['userName']) && is_string($body['userName'])) {
            $scim->user_name = $body['userName'];
        }
        if (array_key_exists('externalId', $body)) {
            $scim->external_id = is_string($body['externalId']) ? $body['externalId'] : null;
        }
        if (isset($body['name']) || isset($body['displayName'])) {
            $name = Scim::nameOf($body, $user->email);
            if ($name !== $user->name) {
                $user->forceFill(['name' => Str::limit($name, 255, '')])->save();
            }
        }

        // The sign-in email isn't changed from SCIM: a provider could
        // otherwise point an account at an address it doesn't own.
        if (isset($body['emails']) && ($sent = Scim::emailOf($body)) !== '' && $sent !== Str::lower($user->email)) {
            Activity::system($workspace, 'scim.email_kept', $user->name, meta: ['email' => $user->email, 'sent' => $sent]);
        }

        $scim->save();

        if (array_key_exists('active', $body)) {
            $active = filter_var($body['active'], FILTER_VALIDATE_BOOLEAN);
            if ($active !== $scim->active) {
                $scim->forceFill(['active' => $active])->save();
            }
            Scim::applyActive($workspace, $scim, $active);
        }
    }

    private function rename(Workspace $workspace, ScimGroup $group, string $name): void
    {
        $name = trim($name);
        if ($name === '' || $name === $group->display_name) {
            return;
        }
        $from = $group->display_name;
        $group->forceFill(['display_name' => $name])->save();
        Activity::system($workspace, 'scim.group_rename', $name, meta: ['from' => $from]);
        $this->resyncRoles($workspace, $group->members()->pluck('northdeck_scim_users.id')->all());
    }

    /**
     * @param  list<int>  $before
     * @param  list<int>  $after
     */
    private function membersChanged(Workspace $workspace, ScimGroup $group, array $before, array $after): void
    {
        $added = array_diff($after, $before);
        $removed = array_diff($before, $after);
        if ($added === [] && $removed === []) {
            return;
        }

        Activity::system($workspace, 'scim.group_members', $group->display_name, meta: array_filter(['added' => count($added) ?: null, 'removed' => count($removed) ?: null]));
        $this->resyncRoles($workspace, [...$added, ...$removed]);
    }

    /** @param  array<int, int>  $scimUserIds */
    private function resyncRoles(Workspace $workspace, array $scimUserIds): void
    {
        ScimUser::query()->whereIn('id', $scimUserIds)->with('user')->get()
            ->each(fn (ScimUser $scim) => Scim::syncRole($workspace, $scim));
    }

    /**
     * @param  array<int, mixed>  $members
     * @return list<int>
     */
    private function memberIds(Workspace $workspace, array $members): array
    {
        $uuids = array_values(array_filter(array_map(fn ($m) => is_array($m) && is_string($m['value'] ?? null) ? $m['value'] : null, $members)));

        return $uuids === [] ? [] : array_values(ScimUser::query()->where('workspace_id', $workspace->id)->whereIn('uuid', $uuids)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /** @return list<int> */
    private function currentMembers(ScimGroup $group): array
    {
        return array_values($group->members()->pluck('northdeck_scim_users.id')->map(fn ($id) => (int) $id)->all());
    }

    /** @return array{0: string, 1: string}|null `attr eq "value"` */
    private function filter(Request $request): ?array
    {
        $filter = trim((string) $request->query('filter', ''));

        return preg_match('/^([A-Za-z.]+)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"$/i', $filter, $m) === 1 ? [$m[1], stripslashes($m[2])] : null;
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @param  callable(T): array<string, mixed>  $present
     */
    private function list(Request $request, $query, callable $present): JsonResponse
    {
        $start = max(1, (int) $request->query('startIndex', 1));
        $count = min(200, max(0, (int) $request->query('count', 100)));
        $total = (clone $query)->count();
        $items = $query->skip($start - 1)->take($count)->get();

        return Scim::json([
            'schemas' => [Scim::LIST],
            'totalResults' => $total,
            'startIndex' => $start,
            'itemsPerPage' => $items->count(),
            'Resources' => $items->map(fn ($item) => $present($item))->values()->all(),
        ]);
    }

    private function workspace(Request $request): Workspace
    {
        /** @var Workspace */
        return $request->attributes->get('scim_workspace');
    }

    private function findUser(Request $request, string $id): ?ScimUser
    {
        return ScimUser::query()->where('workspace_id', $this->workspace($request)->id)->where('uuid', $id)->with(['user', 'groups'])->first();
    }

    private function findGroup(Request $request, string $id): ?ScimGroup
    {
        return ScimGroup::query()->where('workspace_id', $this->workspace($request)->id)->where('uuid', $id)->first();
    }
}
