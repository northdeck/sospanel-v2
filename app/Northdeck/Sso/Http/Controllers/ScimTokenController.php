<?php

namespace App\Northdeck\Sso\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Workspace;
use App\Northdeck\Scim\ScimToken;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/** The workspace's SCIM token: made (or replaced) and revoked from SSO settings. */
final class ScimTokenController extends Controller
{
    #[Audited]
    public function store(Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        abort_unless($workspace->hasScim(), 404);

        [, $secret] = ScimToken::issue($workspace);
        Activity::record($workspace, 'scim.token_create');

        // Shown once; only its hash is kept.
        Inertia::flash('scimToken', $secret);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copy the SCIM token now. It won’t be shown again.')]);

        return back();
    }

    #[Audited]
    public function destroy(Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);

        if (ScimToken::query()->where('workspace_id', $workspace->id)->delete() === 0) {
            AuditContext::nothingChanged();

            return back();
        }

        Activity::record($workspace, 'scim.token_revoke');

        return back();
    }
}
