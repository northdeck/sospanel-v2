<?php

namespace App\Northdeck\Sso\Support;

/**
 * Every URL an identity provider is given lives on one canonical host: the
 * panel's own APP_URL. Workspaces on other hosts (the dashboard domain, a
 * custom domain, an old subdomain) still sign in through it, so one redirect
 * URI or ACS URL works for every way the panel is reached.
 */
final class SsoUrls
{
    /** @param  array<string, mixed>|object  $parameters */
    public static function route(string $name, array|object $parameters = []): string
    {
        return self::root().route($name, $parameters, false);
    }

    public static function root(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public static function host(): string
    {
        return (string) parse_url(self::root(), PHP_URL_HOST);
    }
}
