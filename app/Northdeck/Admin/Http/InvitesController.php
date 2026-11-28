<?php

namespace App\Northdeck\Admin\Http;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\AdminAction;
use App\Models\User;
use App\Models\Workspace;
use App\Northdeck\Admin\PanelInvite;
use App\Northdeck\Admin\PanelInviteSent;
use App\Notifications\WorkspaceInvitationSent;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Admin invites: they let an email make an account whatever the sign-up
 * rules say. With a workspace, the person also gets that workspace's own
 * invitation, and joins it once their account exists.
 */
final class InvitesController extends Controller
{
    #[Audited]
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'workspace_id' => ['nullable', 'integer', Rule::exists('workspaces', 'id')],
            'role' => ['nullable', Rule::in(array_map(fn (WorkspaceRole $r) => $r->value, WorkspaceRole::assignable()))],
        ]);
        $email = Str::lower(trim($data['email']));

        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => __(':email already has an account. Add them from the workspace’s Members page.', ['email' => $email])]);
        }

        $workspace = isset($data['workspace_id']) ? Workspace::query()->whereKey((int) $data['workspace_id'])->first() : null;

        PanelInvite::query()->pending()->whereRaw('lower(email) = ?', [$email])->delete();
        $invite = new PanelInvite([
            'email' => $email,
            'workspace_id' => $workspace?->id,
            'expires_at' => now()->addDays(max(1, (int) config('northdeck.signups.invite_days', 14))),
        ]);
        $invite->invited_by = $request->user()?->id;
        $invite->save();

        $this->send($request, $invite, WorkspaceRole::tryFrom((string) ($data['role'] ?? '')) ?? WorkspaceRole::Member);

        AdminAction::record($request->user(), 'northdeck.invite', meta: ['email' => $email, 'workspace' => $workspace?->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invite sent to :email.', ['email' => $email])]);

        return back();
    }

    #[Audited]
    public function resend(Request $request, PanelInvite $invite): RedirectResponse
    {
        abort_if($invite->accepted_at !== null, 404);

        $invite->expires_at = now()->addDays(max(1, (int) config('northdeck.signups.invite_days', 14)));
        $invite->save();
        $this->send($request, $invite, WorkspaceRole::Member, resend: true);

        AdminAction::record($request->user(), 'northdeck.invite_resend', meta: ['email' => $invite->email]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent again to :email.', ['email' => $invite->email])]);

        return back();
    }

    #[Audited]
    public function destroy(Request $request, PanelInvite $invite): RedirectResponse
    {
        $invite->delete();
        AdminAction::record($request->user(), 'northdeck.invite_revoke', meta: ['email' => $invite->email]);

        return back();
    }

    private function send(Request $request, PanelInvite $invite, WorkspaceRole $role, bool $resend = false): void
    {
        $workspace = $invite->workspace;
        $admin = $request->user();

        if ($workspace === null || $admin === null) {
            Notification::route('mail', $invite->email)->notify(new PanelInviteSent($invite));

            return;
        }

        // The workspace's own invitation: its email links to sign-up, and the
        // person joins the workspace once they have an account.
        $invitation = $workspace->invitations()->firstOrNew(['email' => $invite->email]);
        if (! $resend || ! $invitation->exists) {
            $invitation->role = $role;
            $invitation->custom_role_id = null;
        }
        $invitation->sentBy($admin);
        $token = $invitation->refreshToken();
        $invitation->save();

        Activity::record($workspace, 'member.invite', $invitation->email, meta: ['role' => $invitation->role->value, 'via' => 'admin']);
        Notification::route('mail', $invite->email)->notify(new WorkspaceInvitationSent($invitation, $token));
    }
}
