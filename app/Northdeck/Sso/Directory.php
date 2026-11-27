<?php

namespace App\Northdeck\Sso;

use App\Models\Workspace;
use App\Models\WorkspaceEmailDomain;
use App\Northdeck\Sso\Models\SsoConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Finds the connection for an email address or a workspace. */
final class Directory
{
    public static function forEmail(string $email): ?SsoConnection
    {
        $domain = Str::lower(trim(Str::after($email, '@')));
        if ($domain === '' || ! str_contains($email, '@')) {
            return null;
        }

        // A domain can only be verified by one workspace, so this is unambiguous.
        $verified = WorkspaceEmailDomain::query()->where('domain', $domain)->whereNotNull('verified_at')->first();
        if ($verified !== null) {
            $connection = SsoConnection::query()
                ->where('enabled', true)
                ->whereHas('domains', fn ($q) => $q->whereKey($verified->id))
                ->orderBy('id')
                ->first();

            if ($connection !== null && $connection->workspace?->hasSso()) {
                return $connection;
            }
        }

        $instance = InstanceConnection::get();

        return $instance !== null && $instance->coversEmail($email) ? $instance : null;
    }

    /** @return Collection<int, SsoConnection> */
    public static function forWorkspace(Workspace $workspace): Collection
    {
        if (! $workspace->hasSso()) {
            return new Collection;
        }

        $connections = SsoConnection::query()
            ->where('workspace_id', $workspace->id)
            ->where('enabled', true)
            ->where('is_instance', false)
            ->orderBy('name')
            ->get();

        $instance = InstanceConnection::get();
        if ($instance !== null && $instance->workspace_id === $workspace->id) {
            $connections->push($instance);
        }

        return $connections;
    }
}
