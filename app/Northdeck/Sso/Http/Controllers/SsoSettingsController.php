<?php

namespace App\Northdeck\Sso\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WorkspaceEmailDomainController;
use App\Models\Activity;
use App\Models\McpToken;
use App\Models\Workspace;
use App\Northdeck\Scim\ScimToken;
use App\Northdeck\Sso\Enforcement;
use App\Northdeck\Sso\InstanceConnection;
use App\Northdeck\Sso\Models\SsoConnection;
use App\Northdeck\Sso\Models\SsoGroupMapping;
use App\Northdeck\Sso\Oidc\Discovery;
use App\Northdeck\Sso\Oidc\OidcClient;
use App\Northdeck\Sso\Presets;
use App\Northdeck\Sso\Saml\SamlService;
use App\Northdeck\Sso\SsoException;
use App\Northdeck\Sso\Support\SsoUrls;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Settings › Single sign-on. */
final class SsoSettingsController extends Controller
{
    /** Settings that are plain text in the form, per protocol. */
    private const array OIDC_FIELDS = ['issuer', 'client_id', 'scopes', 'tenant', 'hosted_domain', 'email_claim', 'name_claim', 'groups_claim'];

    private const array SAML_FIELDS = ['metadata_url', 'idp_entity_id', 'idp_sso_url', 'idp_slo_url', 'email_attribute', 'name_attribute', 'groups_claim', 'subject_attribute', 'name_id_format'];

    public function show(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('manageSettings', $workspace);
        abort_unless($workspace->hasSso(), 404);

        $instance = InstanceConnection::configured() ? rescue(fn () => InstanceConnection::get(), null, false) : null;
        $marker = $request->session()->get('northdeck_sso');

        return Inertia::render('workspace/settings/Sso', [
            'connections' => SsoConnection::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_instance', false)
                ->with('groupMappings')
                ->orderBy('name')
                ->get()
                ->map(fn (SsoConnection $c) => $c->toPageProps())
                ->values()
                ->all(),
            'instance' => $instance !== null && $instance->workspace_id === $workspace->id ? $instance->toPageProps() : null,
            'presets' => Presets::toPageProps(),
            'roles' => array_map(fn (WorkspaceRole $r) => ['value' => $r->value, 'label' => $r->label()], WorkspaceRole::assignable()),
            'emailDomains' => WorkspaceEmailDomainController::props($workspace),
            'requireSso' => (bool) $workspace->require_sso,
            'requireGoogle' => (bool) $workspace->require_google_sign_in,
            'isOwner' => $workspace->owner_id === $request->user()->id,
            'canBreakGlass' => Enforcement::canBreakGlass($workspace, $request->user()),
            'signedInWithSso' => is_array($marker) && Enforcement::satisfiedBy($request, $workspace, $request->user()),
            'base' => $workspace->url('/settings/sso'),
            'callbackHost' => SsoUrls::root(),
            'directUrl' => SsoUrls::route('northdeck.sso.workspace', ['workspace' => $workspace->slug]),
            'oidcCallbackUrl' => SsoUrls::route('northdeck.sso.oidc.callback'),
            'scim' => $workspace->hasScim() ? [
                'baseUrl' => SsoUrls::root().'/api/scim/v2',
                'hasToken' => ($token = ScimToken::query()->where('workspace_id', $workspace->id)->first()) !== null,
                'lastUsedAt' => $token?->last_used_at?->toIso8601String(),
            ] : null,
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        abort_unless($workspace->hasSso(), 404);

        $data = $this->validated($request, $workspace, null);
        $preset = (string) $data['preset'];
        $protocol = Presets::get($preset)['protocol'] ?? SsoConnection::OIDC;

        $connection = new SsoConnection([
            'workspace_id' => $workspace->id,
            'protocol' => $protocol,
            'preset' => $preset,
        ]);
        $this->fill($connection, $data);

        if ($connection->isSaml()) {
            $pair = SamlService::newKeyPair(SsoUrls::host() ?: $workspace->slug);
            $connection->sp_private_key = $pair['key'];
            $connection->sp_certificate = $pair['certificate'];
        }

        DB::transaction(function () use ($connection, $data): void {
            $connection->save();
            $connection->domains()->sync($data['domain_ids'] ?? []);
        });

        Activity::record($workspace, 'sso.connection_create', $connection->name, meta: ['type' => $connection->protocol]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added :name. Paste the URLs into your identity provider, then test it.', ['name' => $connection->name])]);

        return redirect()->to($workspace->url('/settings/sso').'#connection-'.$connection->uuid);
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, SsoConnection $connection): RedirectResponse
    {
        $this->authorizeConnection($workspace, $connection);

        $data = $this->validated($request, $workspace, $connection);
        $wasEnabled = $connection->enabled;
        $before = [$connection->stringSetting('issuer'), $connection->stringSetting('tenant'), $connection->stringSetting('idp_entity_id')];

        $this->fill($connection, $data);
        $domainsChanged = DB::transaction(function () use ($connection, $data): bool {
            $connection->save();
            $changes = $connection->domains()->sync($data['domain_ids'] ?? []);

            return array_filter($changes) !== [];
        });

        if (! $connection->wasChanged() && ! $domainsChanged) {
            AuditContext::nothingChanged();

            return back();
        }

        if ($wasEnabled !== $connection->enabled) {
            Activity::record($workspace, $connection->enabled ? 'sso.connection_enable' : 'sso.connection_disable', $connection->name);
        } else {
            Activity::record($workspace, 'sso.connection_update', $connection->name, meta: ['fields' => array_values(array_diff(array_keys($connection->getChanges()), ['updated_at']))]);
        }

        // A different identity provider behind the same connection could hand
        // out the same subjects for different people, so start linking afresh.
        $after = [$connection->stringSetting('issuer'), $connection->stringSetting('tenant'), $connection->stringSetting('idp_entity_id')];
        if ($before !== $after && ($count = $connection->identities()->count()) > 0) {
            $connection->identities()->delete();
            Activity::record($workspace, 'sso.identities_reset', $connection->name, meta: ['count' => $count]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved :name.', ['name' => $connection->name])]);

        return back();
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, SsoConnection $connection): RedirectResponse
    {
        $this->authorizeConnection($workspace, $connection);

        $others = SsoConnection::query()->where('workspace_id', $workspace->id)->where('enabled', true)->whereKeyNot($connection->id)->exists();
        if ($workspace->require_sso && ! $others) {
            throw ValidationException::withMessages(['connection' => __('Turn off Require SSO first, or members couldn’t sign in.')]);
        }

        $name = $connection->name;
        $connection->delete();
        Activity::record($workspace, 'sso.connection_delete', $name);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed :name.', ['name' => $name])]);

        return redirect()->to($workspace->url('/settings/sso'));
    }

    #[Audited]
    public function addMapping(Request $request, Workspace $workspace, SsoConnection $connection): RedirectResponse
    {
        $this->authorizeConnection($workspace, $connection);

        $data = $request->validate([
            'group' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(array_map(fn (WorkspaceRole $r) => $r->value, WorkspaceRole::assignable()))],
        ]);

        $role = WorkspaceRole::from($data['role']);
        if (! $workspace->canGrant($request->user(), $role->permissions())) {
            throw ValidationException::withMessages(['role' => __('You can’t give a role with more access than your own.')]);
        }

        $mapping = SsoGroupMapping::query()->firstOrNew(['connection_id' => $connection->id, 'group' => trim($data['group'])]);
        $existed = $mapping->exists;
        if ($existed && $mapping->role === $role->value) {
            AuditContext::nothingChanged();

            return back();
        }
        $mapping->role = $role->value;
        $mapping->save();

        Activity::record($workspace, $existed ? 'sso.role_mapping_update' : 'sso.role_mapping_add', $mapping->group, meta: ['role' => $role->label(), 'connection' => $connection->name]);

        return back();
    }

    #[Audited]
    public function removeMapping(Workspace $workspace, SsoConnection $connection, SsoGroupMapping $mapping): RedirectResponse
    {
        $this->authorizeConnection($workspace, $connection);
        abort_unless($mapping->connection_id === $connection->id, 404);

        $mapping->delete();
        Activity::record($workspace, 'sso.role_mapping_remove', $mapping->group, meta: ['connection' => $connection->name]);

        return back();
    }

    #[Audited]
    public function updateRequire(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        abort_unless($workspace->hasSso(), 404);
        $require = (bool) $request->validate(['require' => ['required', 'boolean']])['require'];

        if ($require) {
            $ready = SsoConnection::query()->where('workspace_id', $workspace->id)->where('enabled', true)->whereHas('domains', fn ($q) => $q->whereNotNull('verified_at'))->exists();
            if (! $ready) {
                throw ValidationException::withMessages(['require' => __('Add a connection with a verified domain first.')]);
            }
            if ($workspace->require_google_sign_in) {
                throw ValidationException::withMessages(['require' => __('Turn off Require Google sign-in first. Members can only be held to one.')]);
            }
            $user = $request->user();
            if (! Enforcement::canBreakGlass($workspace, $user) && ! Enforcement::satisfiedBy($request, $workspace, $user)) {
                throw ValidationException::withMessages(['require' => __('Sign in with SSO first, so you can still get in.')]);
            }
        }

        if ((bool) $workspace->require_sso === $require) {
            AuditContext::nothingChanged();

            return back();
        }

        $workspace->forceFill(['require_sso' => $require])->save();
        if ($require) {
            // Like Require Google sign-in: AI apps people connected with their
            // own sign-in have to connect again through SSO.
            McpToken::query()->active()
                ->where('workspace_id', $workspace->id)
                ->where('kind', 'oauth')
                ->where('user_id', '!=', $workspace->owner_id)
                ->get()
                ->each(fn (McpToken $token) => $token->revoke());
        }

        Activity::record($workspace, $require ? 'sso.require_on' : 'sso.require_off');
        Inertia::flash('toast', ['type' => 'success', 'message' => $require ? __('Members now sign in with SSO.') : __('SSO is no longer required.')]);

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Workspace $workspace, ?SsoConnection $connection): array
    {
        $preset = $connection->preset ?? (string) $request->input('preset');
        $protocol = $connection->protocol ?? (Presets::get($preset)['protocol'] ?? null);

        $data = $request->validate([
            'preset' => [$connection === null ? 'required' : 'nullable', Rule::in(Presets::keys())],
            'name' => ['required', 'string', 'max:100'],
            'enabled' => ['boolean'],
            'jit' => ['boolean'],
            'single_logout' => ['boolean'],
            'default_role' => ['required', Rule::in(array_map(fn (WorkspaceRole $r) => $r->value, WorkspaceRole::assignable()))],
            'domain_ids' => ['array'],
            'domain_ids.*' => ['integer', Rule::exists('workspace_email_domains', 'id')->where('workspace_id', $workspace->id)],
            'settings' => ['array'],
            'settings.*' => ['nullable', 'string', 'max:2048'],
            'client_secret' => ['nullable', 'string', 'max:2048'],
            'idp_metadata_xml' => ['nullable', 'string', 'max:500000'],
            'idp_certificate' => ['nullable', 'string', 'max:20000'],
            'want_encrypted' => ['boolean'],
            'allow_idp_initiated' => ['boolean'],
        ]);

        $role = WorkspaceRole::from($data['default_role']);
        if (! $workspace->canGrant($request->user(), $role->permissions())) {
            throw ValidationException::withMessages(['default_role' => __('You can’t give a role with more access than your own.')]);
        }

        $fields = $protocol === SsoConnection::SAML ? self::SAML_FIELDS : self::OIDC_FIELDS;
        $settings = array_map(fn ($v) => trim((string) $v), Arr::only((array) ($data['settings'] ?? []), $fields));

        try {
            if ($protocol === SsoConnection::OIDC) {
                if ($preset === 'entra') {
                    if (($settings['tenant'] ?? '') === '') {
                        throw new SsoException(__('Add your tenant ID, or organizations.'));
                    }
                } elseif (($settings['issuer'] ?? '') === '') {
                    throw new SsoException(__('Add the issuer URL.'));
                }
                if (($settings['client_id'] ?? '') === '') {
                    throw new SsoException(__('Add the client ID.'));
                }
                $probe = new SsoConnection(['preset' => $preset, 'settings' => $settings]);
                app(Discovery::class)->document(app(OidcClient::class)->issuer($probe), fresh: true);
            } else {
                $settings = [...$settings, ...$this->samlIdp($data, $settings, $connection)];
            }
        } catch (SsoException $e) {
            throw ValidationException::withMessages(['settings' => $e->getMessage()]);
        }

        $data['settings'] = [
            ...$settings,
            'want_encrypted' => (bool) ($data['want_encrypted'] ?? $connection?->setting('want_encrypted', false) ?? false),
            'allow_idp_initiated' => (bool) ($data['allow_idp_initiated'] ?? $connection?->setting('allow_idp_initiated', false) ?? false),
            'single_logout' => (bool) ($data['single_logout'] ?? $connection?->setting('single_logout', true) ?? true),
        ];

        return $data;
    }

    /**
     * The identity provider's side of SAML, from (in order) uploaded XML, the
     * metadata URL, or fields typed in by hand.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $settings
     * @return array<string, mixed>
     */
    private function samlIdp(array $data, array $settings, ?SsoConnection $connection): array
    {
        if (filled($data['idp_metadata_xml'] ?? null)) {
            return SamlService::parseIdpMetadata((string) $data['idp_metadata_xml']);
        }
        if (($settings['metadata_url'] ?? '') !== '' && $settings['metadata_url'] !== $connection?->stringSetting('metadata_url')) {
            return SamlService::fetchIdpMetadata($settings['metadata_url']);
        }

        $certs = filled($data['idp_certificate'] ?? null)
            ? [SamlService::normalizeCertificate((string) $data['idp_certificate'])]
            : (array) ($connection?->setting('idp_certificates', []) ?? []);

        if (($settings['idp_entity_id'] ?? '') === '' || ($settings['idp_sso_url'] ?? '') === '' || $certs === []) {
            throw new SsoException(__('Add the metadata URL, upload the metadata XML, or fill in the entity ID, sign-in URL and certificate.'));
        }

        return ['idp_certificates' => array_values($certs)];
    }

    /** @param  array<string, mixed>  $data */
    private function fill(SsoConnection $connection, array $data): void
    {
        $connection->fill([
            'name' => trim((string) $data['name']),
            'enabled' => (bool) ($data['enabled'] ?? $connection->enabled ?? true),
            'jit' => (bool) ($data['jit'] ?? $connection->jit ?? true),
            'default_role' => (string) $data['default_role'],
        ]);

        // Keep what the form didn't send (a blank secret means "unchanged").
        $connection->settings = [...($connection->settings ?? []), ...$data['settings']];

        if (filled($data['client_secret'] ?? null)) {
            $connection->client_secret = (string) $data['client_secret'];
        }
    }

    private function authorizeConnection(Workspace $workspace, SsoConnection $connection): void
    {
        Gate::authorize('manageSettings', $workspace);
        abort_unless($workspace->hasSso() && $connection->workspace_id === $workspace->id && ! $connection->is_instance, 404);
    }
}
