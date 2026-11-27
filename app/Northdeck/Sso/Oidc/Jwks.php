<?php

namespace App\Northdeck\Sso\Oidc;

use App\Northdeck\Sso\SsoException;
use App\Northdeck\Sso\Support\IdpHttp;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A provider's signing keys, cached. When a token names a key we don't have,
 * the keys are fetched again (at most once a minute), so rotation just works.
 */
final class Jwks
{
    /** @return array<string, Key> */
    public function keys(string $uri, bool $fresh = false): array
    {
        $key = 'northdeck:sso:oidc:jwks:'.sha1($uri);

        if ($fresh) {
            if (! Cache::add($key.':refetched', true, 60)) {
                $fresh = false;
            } else {
                Cache::forget($key);
            }
        }

        /** @var array<string, mixed> $set */
        $set = Cache::remember($key, (int) config('northdeck.sso.cache_ttl', 3600), function () use ($uri): array {
            $response = IdpHttp::get($uri);
            $json = $response->successful() ? $response->json() : null;
            if (! is_array($json) || ! is_array($json['keys'] ?? null)) {
                throw new SsoException('The provider’s signing keys couldn’t be read.');
            }

            // Only signing keys; an encryption key in the set is no use here.
            $json['keys'] = array_values(array_filter($json['keys'], fn ($k) => is_array($k) && ($k['use'] ?? 'sig') === 'sig'));

            return $json;
        });

        try {
            return JWK::parseKeySet($set, 'RS256');
        } catch (Throwable) {
            throw new SsoException('The provider’s signing keys couldn’t be read.');
        }
    }
}
