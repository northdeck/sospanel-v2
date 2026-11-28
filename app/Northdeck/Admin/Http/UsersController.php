<?php

namespace App\Northdeck\Admin\Http;

use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\User;
use App\Notifications\EmailChanged;
use App\Rules\UniqueEmail;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class UsersController extends Controller
{
    use ProfileValidationRules;

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
                $query->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like]));
            })
            ->withCount('workspaces')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'verified' => $user->email_verified_at !== null,
                'twoFactor' => $user->two_factor_confirmed_at !== null,
                'admin' => (bool) $user->is_admin,
                'suspended' => $user->suspended_at !== null,
                'workspaces' => (int) $user->getAttribute('workspaces_count'),
                'createdAt' => $user->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/NorthdeckUsers', [
            'users' => $users,
            'search' => $search,
            'me' => $request->user()?->id,
        ]);
    }

    #[Audited]
    public function update(Request $request, User $user): RedirectResponse
    {
        $emailRules = $this->emailRules($user->id);
        if (mb_strtolower(trim((string) $request->input('email'))) === mb_strtolower($user->email)) {
            $emailRules = array_values(array_filter($emailRules, fn ($rule) => ! $rule instanceof UniqueEmail));
        }

        $data = $request->validate([
            'name' => $this->nameRules(),
            'email' => $emailRules,
            'verified' => ['boolean'],
            'admin' => ['boolean'],
        ]);

        $admin = $request->user();
        $makeAdmin = (bool) ($data['admin'] ?? $user->is_admin);
        if ($user->is($admin) && ! $makeAdmin) {
            throw ValidationException::withMessages(['admin' => __('You can’t take admin away from yourself. Ask another admin.')]);
        }

        $user->fill(['name' => $data['name'], 'email' => mb_strtolower(trim($data['email']))]);
        $emailChanged = $user->isDirty('email');
        if ($emailChanged) {
            $user->email_verified_at = ($data['verified'] ?? false) ? now() : null;
        }
        $user->is_admin = $makeAdmin;
        $user->save();

        $changed = array_values(array_diff(array_keys($user->getChanges()), ['updated_at', 'created_at']));
        if ($changed === []) {
            AuditContext::nothingChanged();

            return back();
        }

        AdminAction::record($admin, 'user.updated', $user, [
            'changed' => $changed,
            ...($emailChanged ? ['email' => $user->email] : []),
        ]);

        if ($emailChanged) {
            $previous = $user->getPrevious()['email'] ?? null;
            if (is_string($previous) && $previous !== '') {
                rescue(fn () => Notification::route('mail', $previous)->notify(new EmailChanged($user->name, $user->email)));
            }
            if ($user->email_verified_at === null) {
                rescue(fn () => $user->sendEmailVerificationNotification());
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved :name.', ['name' => $user->name])]);

        return back();
    }
}
