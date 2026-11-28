<?php

namespace App\Northdeck\Roles;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceCustomRole;
use App\Support\Workspaces\RoleChoice;

/**
 * Roles that SSO group mappings, a connection's default role and SCIM hand
 * out. A value is a built-in role ("admin", "member", "viewer") or a
 * workspace's custom role ("custom:12"), the same values the Members page
 * uses (RoleChoice).
 */
final class RoleMapping
{
    /** The role a stored value names, or null if it's gone or not allowed. */
    public static function choice(Workspace $workspace, ?string $value): ?RoleChoice
    {
        $choice = RoleChoice::parse($workspace, $value);

        return $choice instanceof RoleChoice ? $choice : null;
    }

    /**
     * The role with the most access among the values. A built-in role wins a
     * tie with a custom one.
     *
     * @param  iterable<string>  $values
     */
    public static function best(Workspace $workspace, iterable $values): ?RoleChoice
    {
        $best = null;
        foreach ($values as $value) {
            $choice = self::choice($workspace, $value);
            if ($choice !== null && ($best === null || self::rank($choice) > self::rank($best))) {
                $best = $choice;
            }
        }

        return $best;
    }

    public static function rank(RoleChoice $choice): int
    {
        return count($choice->permissions()) * 2 + ($choice->custom === null ? 1 : 0);
    }

    /** @return array{role: string, custom_role_id: int|null} */
    public static function pivot(RoleChoice $choice): array
    {
        return ['role' => $choice->role->value, 'custom_role_id' => $choice->custom?->id];
    }

    public static function value(RoleChoice $choice): string
    {
        return RoleChoice::value($choice->role->value, $choice->custom?->id);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(Workspace $workspace): array
    {
        $options = array_map(
            fn (WorkspaceRole $role) => ['value' => $role->value, 'label' => $role->label()],
            array_values(array_filter(WorkspaceRole::assignable(), fn (WorkspaceRole $role) => ! $role->isStaffRole() || $workspace->hasStaffRoles())),
        );

        if ($workspace->hasCustomRoles()) {
            foreach ($workspace->customRoles()->get() as $custom) {
                /** @var WorkspaceCustomRole $custom */
                $options[] = ['value' => "custom:{$custom->id}", 'label' => $custom->name];
            }
        }

        return $options;
    }
}
