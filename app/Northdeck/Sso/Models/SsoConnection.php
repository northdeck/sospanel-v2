<?php

namespace App\Northdeck\Sso\Models;

use App\Models\Workspace;
use App\Models\WorkspaceEmailDomain;
use App\Northdeck\Sso\Presets;
use App\Northdeck\Sso\Support\SsoUrls;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One identity provider a workspace (or the whole install) signs in with.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $workspace_id
 * @property string $name
 * @property string $protocol oidc or saml
 * @property string $preset
 * @property bool $enabled
 * @property bool $is_instance
 * @property array<string, mixed> $settings
 * @property string|null $client_secret
 * @property string|null $sp_private_key
 * @property string|null $sp_certificate
 * @property bool $jit
 * @property string $default_role
 * @property CarbonImmutable|null $last_tested_at
 * @property array<string, mixed>|null $last_test
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace|null $workspace
 */
class SsoConnection extends Model
{
    public const string OIDC = 'oidc';

    public const string SAML = 'saml';

    protected $table = 'northdeck_sso_connections';

    protected $guarded = ['id'];

    // Secrets never leave the server once saved.
    protected $hidden = ['client_secret', 'sp_private_key'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'client_secret' => 'encrypted',
            'sp_private_key' => 'encrypted',
            'enabled' => 'boolean',
            'is_instance' => 'boolean',
            'jit' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SsoConnection $connection): void {
            $connection->uuid = $connection->uuid ?: (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsToMany<WorkspaceEmailDomain, $this> */
    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(WorkspaceEmailDomain::class, 'northdeck_sso_connection_domains', 'connection_id', 'workspace_email_domain_id')
            ->withTimestamps();
    }

    /** @return HasMany<SsoIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(SsoIdentity::class, 'connection_id');
    }

    /** @return HasMany<SsoGroupMapping, $this> */
    public function groupMappings(): HasMany
    {
        return $this->hasMany(SsoGroupMapping::class, 'connection_id')->orderBy('group');
    }

    public function isOidc(): bool
    {
        return $this->protocol === self::OIDC;
    }

    public function isSaml(): bool
    {
        return $this->protocol === self::SAML;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function stringSetting(string $key, string $default = ''): string
    {
        $value = $this->setting($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * The email domains this connection signs people in for, all verified.
     * The install's own connection trusts the domains set in its config.
     *
     * @return list<string>
     */
    public function verifiedDomains(): array
    {
        if ($this->is_instance) {
            return array_values(array_filter(array_map(
                fn (string $domain) => Str::lower(trim($domain)),
                explode(',', (string) config('northdeck.sso.instance.domains', '')),
            )));
        }

        return array_values($this->domains()->whereNotNull('verified_at')->pluck('domain')
            ->map(fn ($domain) => Str::lower((string) $domain))->all());
    }

    public function coversEmail(string $email): bool
    {
        $domain = Str::lower(Str::after($email, '@'));

        return $domain !== '' && in_array($domain, $this->verifiedDomains(), true);
    }

    public function groupsClaim(): string
    {
        return $this->stringSetting('groups_claim', 'groups');
    }

    public function callbackUrl(): string
    {
        return SsoUrls::route('northdeck.sso.oidc.callback');
    }

    public function acsUrl(): string
    {
        return SsoUrls::route('northdeck.sso.saml.acs', $this);
    }

    public function metadataUrl(): string
    {
        return SsoUrls::route('northdeck.sso.saml.metadata', $this);
    }

    public function sloUrl(): string
    {
        return SsoUrls::route('northdeck.sso.saml.slo', $this);
    }

    public function entityId(): string
    {
        return $this->metadataUrl();
    }

    public function presetLabel(): string
    {
        return Presets::get($this->preset)['label'] ?? $this->preset;
    }

    /** @return array<string, string> What to paste into the identity provider. */
    public function providerUrls(): array
    {
        return $this->isOidc()
            ? [
                'Redirect URI' => $this->callbackUrl(),
                'Sign-out redirect URI' => SsoUrls::route('northdeck.sso.signed-out'),
                'Sign-in URL' => SsoUrls::route('northdeck.sso.start', $this),
            ]
            : [
                'ACS URL' => $this->acsUrl(),
                'Entity ID' => $this->entityId(),
                'Metadata URL' => $this->metadataUrl(),
                'Single logout URL' => $this->sloUrl(),
                'Sign-in URL' => SsoUrls::route('northdeck.sso.start', $this),
            ];
    }

    /** @return array<string, mixed> */
    public function toPageProps(): array
    {
        $settings = $this->settings;
        unset($settings['idp_metadata_xml']);

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'protocol' => $this->protocol,
            'preset' => $this->preset,
            'presetLabel' => $this->presetLabel(),
            'enabled' => $this->enabled,
            'instance' => $this->is_instance,
            'settings' => $settings,
            'hasClientSecret' => filled($this->client_secret),
            'jit' => $this->jit,
            'defaultRole' => $this->default_role,
            'domainIds' => $this->is_instance ? [] : $this->domains()->pluck('workspace_email_domains.id')->all(),
            'domains' => $this->verifiedDomains(),
            'urls' => collect($this->providerUrls())->map(fn (string $url, string $label) => ['label' => $label, 'url' => $url])->values()->all(),
            'groupMappings' => $this->groupMappings->map(fn (SsoGroupMapping $m) => ['id' => $m->id, 'group' => $m->group, 'role' => $m->role])->all(),
            'testUrl' => SsoUrls::route('northdeck.sso.start', $this).'?test=1',
            'lastTestedAt' => $this->last_tested_at?->toIso8601String(),
            'lastTest' => $this->last_test,
            'identities' => $this->identities()->count(),
        ];
    }
}
