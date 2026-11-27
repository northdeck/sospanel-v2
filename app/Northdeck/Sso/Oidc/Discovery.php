<?php

namespace App\Northdeck\Sso\Oidc;

use App\Northdeck\Sso\SsoException;
use App\Northdeck\Sso\Support\IdpHttp;
use Illuminate\Support\Facades\Cache;

/**
 * Reads and caches a provider's /.well-known/openid-configuration.
 */
final class Discovery
{
    /** @return array<string, mixed> */
    public function document(string $issuer, bool $fresh = false): array
    {
        $issuer = rtrim(trim($issuer), '/');
        if ($issuer === '') {
            throw new SsoException('Add the issuer URL first.');
        }

        $key = 'northdeck:sso:oidc:discovery:'.sha1($issuer);
        if ($fresh) {
            Cache::forget($key);
        }

        /** @var array<string, mixed> */
        return Cache::remember($key, (int) config('northdeck.sso.cache_ttl', 3600), function () use ($issuer): array {
            $response = IdpHttp::get($issuer.'/.well-known/openid-configuration');
            $doc = $response->successful() ? $response->json() : null;

            if (! is_array($doc) || ! is_string($doc['issuer'] ?? null) || ! is_string($doc['authorization_endpoint'] ?? null)
                || ! is_string($doc['token_endpoint'] ?? null) || ! is_string($doc['jwks_uri'] ?? null)) {
                throw new SsoException('The issuer has no usable /.well-known/openid-configuration.');
            }

            if (! self::issuerMatches(rtrim($doc['issuer'], '/'), $issuer)) {
                throw new SsoException('The discovery document is for a different issuer ('.$doc['issuer'].').');
            }

            return $doc;
        });
    }

    /**
     * Entra's multi-tenant documents name the issuer with a {tenantid}
     * placeholder, which the ID token's tid fills in.
     */
    public static function issuerMatches(string $documented, string $configured, ?string $tenant = null): bool
    {
        if ($documented === $configured) {
            return true;
        }

        if (str_contains($documented, '{tenantid}')) {
            $pattern = '#^'.str_replace(preg_quote('{tenantid}', '#'), '[0-9a-f-]{36}', preg_quote($documented, '#')).'$#i';
            if ($tenant !== null) {
                return $configured === str_replace('{tenantid}', $tenant, $documented);
            }

            return preg_match('#^https://login\.microsoftonline\.com/(organizations|common|consumers)/v2\.0$#', $configured) === 1
                || preg_match($pattern, $configured) === 1;
        }

        return false;
    }
}
