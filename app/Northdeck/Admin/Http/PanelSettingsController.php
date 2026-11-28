<?php

namespace App\Northdeck\Admin\Http;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Northdeck\Admin\PanelSettings;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PanelSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('admin/NorthdeckPanel', [
            'settings' => PanelSettings::current(config()),
        ]);
    }

    #[Audited]
    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (PanelSettings::FIELDS as $field => $spec) {
            $rules[$field] = ['nullable', ...$spec['rules']];
        }
        /** @var array<string, string|null> $data */
        $data = $request->validate($rules);

        $changed = PanelSettings::save($data);
        if ($changed === []) {
            AuditContext::nothingChanged();
        } else {
            AdminAction::record($request->user(), 'northdeck.panel_settings', meta: ['changed' => $changed]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Panel settings saved.')]);

        return back();
    }
}
