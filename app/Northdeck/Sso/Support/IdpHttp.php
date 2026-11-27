<?php

namespace App\Northdeck\Sso\Support;

use App\Northdeck\Sso\SsoException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Requests to identity providers. Workspace admins type these URLs, so:
 *
 *  - the host is resolved once, over IPv4 and IPv6, and every address it
 *    has must be public (unless NORTHDECK_SSO_ALLOW_PRIVATE_HOSTS);
 *  - the connection is pinned to the address that was checked
 *    (CURLOPT_RESOLVE), so a second DNS answer can't point it somewhere
 *    else (DNS rebinding). TLS still checks the certificate for the host;
 *  - redirects aren't followed, since they would skip both checks.
 */
final class IdpHttp
{
    /**
     * Addresses that are never an identity provider on the internet. The
     * IPv6 forms that carry an IPv4 address (mapped, 6to4, NAT64) are checked
     * by the IPv4 inside them.
     */
    public const array BLOCKED = [
        // IPv4
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // IPv6
        '::/128', '::1/128', '::/96', '100::/64', '2001::/23', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** @var (Closure(string): list<string>)|null swaps DNS out in tests */
    public static ?Closure $resolver = null;

    /**
     * A client for one URL, after the checks above, pinned to the checked
     * address. Use it only for that URL.
     */
    public static function for(string $url): PendingRequest
    {
        ['host' => $host, 'port' => $port, 'ip' => $ip] = self::target($url);

        $pin = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return Http::timeout((int) config('northdeck.sso.http_timeout', 10))
            ->acceptJson()
            ->withUserAgent('NorthdeckPanel-SSO')
            ->withoutRedirecting()
            ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pin}"]]]);
    }

    public static function get(string $url): Response
    {
        $client = self::for($url);

        try {
            return $client->get($url);
        } catch (ConnectionException) {
            throw new SsoException('Couldn’t reach the identity provider.');
        }
    }

    /** @param  array<string, string>  $form */
    public static function postForm(string $url, array $form, ?string $basicUser = null, ?string $basicPassword = null): Response
    {
        $client = self::for($url)->asForm();
        if ($basicUser !== null) {
            $client = $client->withBasicAuth(rawurlencode($basicUser), rawurlencode((string) $basicPassword));
        }

        try {
            return $client->post($url, $form);
        } catch (ConnectionException) {
            throw new SsoException('Couldn’t reach the identity provider.');
        }
    }

    /** @return array{host: string, port: int, ip: string} */
    public static function target(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $allowPrivate = (bool) config('northdeck.sso.allow_private_hosts');

        if ($host === '' || ! in_array($scheme, ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new SsoException('That isn’t a web address.');
        }
        if ($scheme !== 'https' && ! $allowPrivate) {
            throw new SsoException('The identity provider has to use https.');
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            throw new SsoException("Couldn’t find {$host}.");
        }

        // Every address has to pass, not just the one we'd use: otherwise a
        // host with one public and one private answer slips through.
        if (! $allowPrivate) {
            foreach ($ips as $ip) {
                if (! self::isPublic($ip)) {
                    throw new SsoException("{$host} is on a private network. Set NORTHDECK_SSO_ALLOW_PRIVATE_HOSTS=true to allow it.");
                }
            }
        }

        return [
            'host' => $host,
            'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'ip' => $ips[0],
        ];
    }

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $ip;
            }
        }

        // DNS has no answer for names only the system resolver knows
        // (/etc/hosts). Those are checked and pinned the same way.
        if ($ips === []) {
            $ips = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }

    public static function isPublic(string $ip): bool
    {
        $bytes = @inet_pton($ip);
        if ($bytes === false) {
            return false;
        }

        // IPv6 that carries an IPv4 address is judged by that address.
        if (strlen($bytes) === 16) {
            $embedded = match (true) {
                str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff") => substr($bytes, 12, 4), // ::ffff:a.b.c.d
                str_starts_with($bytes, "\x00\x64\xff\x9b".str_repeat("\0", 8)) => substr($bytes, 12, 4), // 64:ff9b::/96
                str_starts_with($bytes, "\x20\x02") => substr($bytes, 2, 4), // 2002::/16
                default => null,
            };
            if ($embedded !== null) {
                return self::isPublic((string) inet_ntop($embedded));
            }
        }

        foreach (self::BLOCKED as $cidr) {
            if (self::inRange($bytes, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $bytes, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $net = inet_pton($network);
        if ($net === false || strlen($net) !== strlen($bytes)) {
            return false;
        }

        $bits = (int) $bits;
        $full = intdiv($bits, 8);
        if (strncmp($bytes, $net, $full) !== 0) {
            return false;
        }
        if ($bits % 8 === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $bits % 8)) & 0xFF;

        return (ord($bytes[$full]) & $mask) === (ord($net[$full]) & $mask);
    }
}
