<?php

use App\Northdeck\Sso\SsoException;
use App\Northdeck\Sso\Support\IdpHttp;

beforeEach(function () {
    config()->set('northdeck.sso.allow_private_hosts', false);
});

afterEach(function () {
    IdpHttp::$resolver = null;
});

test('private, reserved and special addresses are refused, over IPv4 and IPv6', function (string $ip) {
    expect(IdpHttp::isPublic($ip))->toBeFalse();
})->with([
    '127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '198.18.0.1',
    '::1', '::', 'fe80::1', 'fd00::1', 'ff02::1', '2001:db8::1',
    // IPv6 that carries a private IPv4
    '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::a9fe:a9fe', '2002:c0a8:0101::1',
]);

test('public addresses are allowed, over IPv4 and IPv6', function (string $ip) {
    expect(IdpHttp::isPublic($ip))->toBeTrue();
})->with(['93.184.216.34', '1.1.1.1', '2606:4700:4700::1111', '::ffff:93.184.216.34']);

test('a host with any private answer is refused, even if another answer is public', function () {
    IdpHttp::$resolver = fn () => ['93.184.216.34', '::1'];

    expect(fn () => IdpHttp::target('https://idp.example.com/.well-known/openid-configuration'))
        ->toThrow(SsoException::class, 'private network');
});

test('the request is pinned to the address that was checked', function () {
    IdpHttp::$resolver = fn () => ['2606:4700:4700::1111', '93.184.216.34'];

    $options = IdpHttp::for('https://idp.example.com:8443/jwks')->getOptions();

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['idp.example.com:8443:[2606:4700:4700::1111]'])
        ->and($options['allow_redirects'])->toBeFalse();
});

test('plain http and URLs with credentials are refused', function (string $url) {
    IdpHttp::$resolver = fn () => ['93.184.216.34'];

    expect(fn () => IdpHttp::target($url))->toThrow(SsoException::class);
})->with(['http://idp.example.com/', 'https://user:pass@idp.example.com/', 'file:///etc/passwd', 'gopher://idp.example.com/']);

test('NORTHDECK_SSO_ALLOW_PRIVATE_HOSTS lets a LAN identity provider in, still pinned', function () {
    config()->set('northdeck.sso.allow_private_hosts', true);
    IdpHttp::$resolver = fn () => ['192.168.1.20'];

    expect(IdpHttp::for('http://authentik.lan/application/o/panel/')->getOptions()['curl'][CURLOPT_RESOLVE])
        ->toBe(['authentik.lan:80:192.168.1.20']);
});
