<?php

use App\Northdeck\NorthdeckServiceProvider;
use App\Support\InstallPing;

test('the daily ping is off unless SERVEROS_TELEMETRY is true', function () {
    config(['serveros.edition' => 'self-hosted', 'northdeck.telemetry' => false]);
    NorthdeckServiceProvider::applyDefaults(config());

    expect(InstallPing::enabled())->toBeFalse();

    config(['northdeck.telemetry' => true]);
    NorthdeckServiceProvider::applyDefaults(config());

    expect(InstallPing::enabled())->toBeTrue();
});

test('support and source links point at Northdeck, and upstream’s donate ask is hidden', function () {
    config([
        'northdeck.support.email' => 'help@northdeck.example',
        'northdeck.support.hide_upstream_ask' => true,
        'serveros.brand.source_url' => 'https://github.com/serveroshq/panel',
        'serveros.brand.discord_url' => 'https://discord.gg/serveros',
        'serveros.support_ask.enabled' => true,
    ]);

    NorthdeckServiceProvider::applyDefaults(config());

    expect(config('mail.support.address'))->toBe('help@northdeck.example')
        ->and(config('serveros.brand.source_url'))->toBe('https://github.com/northdeck/sos-panel')
        ->and(config('serveros.brand.discord_url'))->toBeNull()
        ->and(config('serveros.support_ask.enabled'))->toBeFalse();
});

test('a source URL set for the upstream key wins', function () {
    config(['serveros.brand.source_url' => 'https://git.example/panel']);

    NorthdeckServiceProvider::applyDefaults(config());

    expect(config('serveros.brand.source_url'))->toBe('https://git.example/panel');
});
