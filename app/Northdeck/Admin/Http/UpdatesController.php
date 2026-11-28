<?php

namespace App\Northdeck\Admin\Http;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Northdeck\Updates\SourceUpdate;
use App\Northdeck\Updates\SourceUpdates;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class UpdatesController extends Controller
{
    public function show(): Response
    {
        SourceUpdates::expireStale();
        $current = SourceUpdates::current();
        $check = SourceUpdates::lastCheck();

        return Inertia::render('admin/NorthdeckUpdates', [
            'repo' => SourceUpdates::repo(),
            'branch' => SourceUpdates::branch(),
            'current' => $current,
            'check' => $check === null ? null : [
                'checkedAt' => now()->setTimestamp((int) $check['checkedAt'])->toIso8601String(),
                'reached' => (bool) $check['reached'],
                'error' => $check['error'] ?? null,
                'latest' => $check['latest'] ?? null,
                'behind' => $check['behind'] ?? null,
                'commits' => $check['commits'] ?? [],
            ],
            'available' => SourceUpdates::available() !== null,
            'updater' => SourceUpdates::updaterSeen(),
            'auto' => (bool) config('northdeck.updates.auto'),
            'autoHour' => is_numeric(config('northdeck.updates.auto_hour')) ? (int) config('northdeck.updates.auto_hour') : null,
            'updates' => SourceUpdate::query()->with('requester:id,name')->latest('id')->limit(10)->get()
                ->map(fn (SourceUpdate $u) => [
                    'id' => $u->id,
                    'commit' => substr($u->commit, 0, 7),
                    'from' => $u->from_commit === null ? null : substr($u->from_commit, 0, 7),
                    'status' => $u->status,
                    'auto' => $u->auto,
                    'by' => $u->requester?->name,
                    'error' => $u->error,
                    'at' => $u->created_at?->toIso8601String(),
                ]),
        ]);
    }

    #[Audited]
    public function check(): RedirectResponse
    {
        $check = SourceUpdates::check();
        AuditContext::nothingChanged();
        Inertia::flash('toast', $check['reached']
            ? ['type' => 'success', 'message' => SourceUpdates::available() === null ? __('This panel runs the newest commit.') : __('There’s a newer commit.')]
            : ['type' => 'error', 'message' => (string) ($check['error'] ?? __('Couldn’t reach GitHub.'))]);

        return back();
    }

    #[Audited]
    public function update(Request $request): RedirectResponse
    {
        try {
            $update = SourceUpdates::request($request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['update' => __($e->getMessage())]);
        }

        AdminAction::record($request->user(), 'northdeck.update', meta: ['commit' => $update->commit, 'from' => $update->from_commit]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Updating. The server starts within a minute; the panel is down for a few minutes while it builds and restarts.')]);

        return back();
    }
}
