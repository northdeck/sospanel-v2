<?php

namespace App\Northdeck\Sso;

use App\Models\Workspace;
use App\Northdeck\Sso\Models\SsoConnection;
use App\Northdeck\Sso\Saml\SamlService;
use App\Northdeck\Sso\Support\SsoUrls;
use Illuminate\Support\Facades\Cache;

/**
 * The install-wide connection set in .env (NORTHDECK_SSO_PROTOCOL and
 * friends). It's kept as a row so identities can point at it, and the row
 * follows the config: change .env and it changes on the next sign-in.
 */
final class InstanceConnection
{
    public static function configured(): bool
    {
        return in_array(config('northdeck.sso.instance.protocol'), [SsoConnection::OIDC, SsoConnection::SAML], true);
    }

    public static function get(): ?SsoConnection
    {
        if (! self::configured()) {
            SsoConnection::query()->where('is_instance', true)->update(['enabled' => false]);

            return null;
        }

        /** @var array<string, mixed> $config */
        $config = config('northdeck.sso.instance');
        $protocol = (string) $config['protocol'];
        $slug = (string) ($config['workspace'] ?? '');

        $settings = $protocol === SsoConnection::OIDC
            ? [
                'issuer' => (string) ($config['oidc']['issuer'] ?? ''),
                'client_id' => (string) ($config['oidc']['client_id'] ?? ''),
                'scopes' => (string) ($config['oidc']['scopes'] ?? 'openid email profile'),
                'groups_claim' => (string) ($config['groups_claim'] ?? 'groups'),
            ]
            : self::samlSettings($config);

        $connection = SsoConnection::query()->firstOrNew(['is_instance' => true]);
        $connection->fill([
            'workspace_id' => $slug === '' ? null : Workspace::query()->where('slug', $slug)->value('id'),
            'name' => (string) ($config['name'] ?? 'Single sign-on'),
            'protocol' => $protocol,
            'preset' => $protocol === SsoConnection::SAML ? 'saml' : (string) ($config['preset'] ?? 'oidc'),
            'enabled' => true,
            'settings' => $settings,
            'jit' => (bool) ($config['jit'] ?? true),
            'default_role' => (string) ($config['default_role'] ?? 'member'),
        ]);
        if ($protocol === SsoConnection::OIDC) {
            $connection->client_secret = (string) ($config['oidc']['client_secret'] ?? '') ?: null;
        }
        if ($protocol === SsoConnection::SAML && blank($connection->sp_private_key)) {
            $pair = SamlService::newKeyPair(SsoUrls::host() ?: 'northdeck-panel');
            $connection->sp_private_key = $pair['key'];
            $connection->sp_certificate = $pair['certificate'];
        }
        if ($connection->isDirty()) {
            $connection->save();
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function samlSettings(array $config): array
    {
        $saml = (array) ($config['saml'] ?? []);
        $url = (string) ($saml['metadata_url'] ?? '');

        $idp = $url !== ''
            ? Cache::remember('northdeck:sso:instance:saml:'.sha1($url), (int) config('northdeck.sso.cache_ttl', 3600), fn () => SamlService::fetchIdpMetadata($url))
            : [
                'idp_entity_id' => (string) ($saml['idp_entity_id'] ?? ''),
                'idp_sso_url' => (string) ($saml['idp_sso_url'] ?? ''),
                'idp_slo_url' => (string) ($saml['idp_slo_url'] ?? ''),
                'idp_certificates' => array_filter([SamlService::normalizeCertificate((string) ($saml['idp_certificate'] ?? ''))]),
            ];

        return [...$idp, 'metadata_url' => $url, 'groups_claim' => (string) ($config['groups_claim'] ?? 'groups')];
    }
}
