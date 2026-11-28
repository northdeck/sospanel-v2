<?php

namespace App\Northdeck\Admin\Http;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Northdeck\Admin\PanelSettings;
use App\Northdeck\Admin\SettingsCatalog;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PanelSettingsController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('admin/NorthdeckSettings', [
            'groups' => PanelSettings::forPage(config()),
            'notManaged' => array_map(
                fn (string $keys, string $why) => ['keys' => $keys, 'why' => $why],
                array_keys(SettingsCatalog::NOT_MANAGED),
                array_values(SettingsCatalog::NOT_MANAGED),
            ),
        ]);
    }

    #[Audited]
    public function update(Request $request): RedirectResponse
    {
        $secrets = array_keys(array_filter(SettingsCatalog::fields(), fn (array $f) => $f['type'] === 'secret'));
        $data = $request->validate([
            'values' => ['array'],
            ...collect(PanelSettings::rules())->mapWithKeys(fn ($rules, $key) => ["values.{$key}" => $rules])->all(),
            'clear' => ['array'],
            'clear.*' => ['string', Rule::in($secrets)],
        ]);

        /** @var array<string, mixed> $values */
        $values = $data['values'] ?? [];
        /** @var list<string> $clear */
        $clear = array_values($data['clear'] ?? []);

        $changed = PanelSettings::save($values, $clear);
        if ($changed === []) {
            AuditContext::nothingChanged();
        } else {
            // Names only: values can be secrets.
            AdminAction::record($request->user(), 'northdeck.panel_settings', meta: ['changed' => $changed]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }
}
