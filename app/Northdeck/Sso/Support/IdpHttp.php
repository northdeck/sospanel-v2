<?php

namespace App\Northdeck\Sso\Support;

use App\Northdeck\Sso\SsoException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Requests to identity providers. Workspace admins type these URLs, so they
 * have to be https and, unless the install allows it, on a public address.
 */
final class IdpHttp
{
    public static function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if ($host === '' || ! in_array($scheme, ['https', 'http'], true)) {
            throw new SsoException('That isn’t a web address.');
        }

        if ((bool) config('northdeck.sso.allow_private_hosts')) {
            return;
        }

        if ($scheme !== 'https') {
            throw new SsoException('The identity provider has to use https.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new SsoException("Couldn’t find {$host}.");
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new SsoException("{$host} is on a private network. Set NORTHDECK_SSO_ALLOW_PRIVATE_HOSTS=true to allow it.");
            }
        }
    }

    public static function client(): PendingRequest
    {
        return Http::timeout((int) config('northdeck.sso.http_timeout', 10))
            ->acceptJson()
            ->withUserAgent('NorthdeckPanel-SSO')
            ->withoutRedirecting();
    }

    public static function get(string $url): Response
    {
        self::assertAllowed($url);

        try {
            return self::client()->get($url);
        } catch (ConnectionException) {
            throw new SsoException('Couldn’t reach the identity provider.');
        }
    }

    /** @param  array<string, string>  $form */
    public static function postForm(string $url, array $form, ?string $basicUser = null, ?string $basicPassword = null): Response
    {
        self::assertAllowed($url);

        $client = self::client()->asForm();
        if ($basicUser !== null) {
            $client = $client->withBasicAuth(rawurlencode($basicUser), rawurlencode((string) $basicPassword));
        }

        try {
            return $client->post($url, $form);
        } catch (ConnectionException) {
            throw new SsoException('Couldn’t reach the identity provider.');
        }
    }
}
