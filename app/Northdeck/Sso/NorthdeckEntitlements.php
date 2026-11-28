<?php

namespace App\Northdeck\Sso;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Models\Workspace;
use App\Support\Entitlements\Entitlements;

/**
 * Northdeck's build has its own SSO and SCIM, and turns on custom roles, so
 * every workspace gets them when they're switched on in config/northdeck.php.
 * Everything else is answered by the panel's own entitlements.
 */
final readonly class NorthdeckEntitlements implements Entitlements
{
    public function __construct(private Entitlements $inner) {}

    public function active(Workspace $workspace): bool
    {
        return $this->inner->active($workspace);
    }

    public function has(Workspace $workspace, Feature $feature): bool
    {
        return match ($feature) {
            Feature::Sso => (bool) config('northdeck.sso.enabled') || $this->inner->has($workspace, $feature),
            Feature::Scim => ((bool) config('northdeck.sso.enabled') && (bool) config('northdeck.scim.enabled')) || $this->inner->has($workspace, $feature),
            Feature::CustomRoles => (bool) config('northdeck.roles.custom') || $this->inner->has($workspace, $feature),
            default => $this->inner->has($workspace, $feature),
        };
    }

    public function limit(Workspace $workspace, Limit $limit): ?int
    {
        return $this->inner->limit($workspace, $limit);
    }
}
