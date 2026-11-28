<?php

namespace App\Northdeck\Admin;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Northdeck's rules for new accounts, on top of upstream's open or invite-only
 * (App\Support\Auth\SignUps):
 *
 *  - an admin invite (PanelInvite) lets an email in, whatever else is set;
 *  - blocked domains never get a new account;
 *  - "domains" lets in only emails on the allowed domains;
 *  - "off" lets in only admin invites.
 *
 * "open" and "invite" are upstream's own modes. The first account on a new
 * install is always allowed, so the installer works.
 */
final class SignUpPolicy
{
    public const array MODES = ['open', 'domains', 'invite', 'off'];

    public static function mode(): string
    {
        $mode = (string) config('northdeck.signups.mode', 'invite');

        return in_array($mode, self::MODES, true) ? $mode : 'invite';
    }

    /**
     * Whether this email may make an account, or null to leave it to
     * upstream's rules.
     */
    public static function decide(string $email): ?bool
    {
        if (User::query()->doesntExist()) {
            return null;
        }
        if (PanelInvite::pendingFor($email)) {
            return true;
        }
        if (self::blocked($email)) {
            return false;
        }

        return match (self::mode()) {
            'domains' => self::onAllowedDomain($email),
            'off' => false,
            default => null,
        };
    }

    /** May SSO or SCIM make an account for this email? */
    public static function ssoMayCreate(string $email): bool
    {
        if (PanelInvite::pendingFor($email)) {
            return true;
        }

        return (bool) config('northdeck.signups.sso', true) && ! self::blocked($email);
    }

    /** Why this email was turned away, in words for the person. */
    public static function refusal(string $email): ?string
    {
        if (self::blocked($email)) {
            return 'Accounts on '.self::domain($email).' can’t be made on this panel. Ask its admin for an invite.';
        }

        return match (self::mode()) {
            'domains' => 'New accounts on this panel are for '.self::allowedList().' emails. Ask its admin for an invite.',
            'off' => 'Sign-up is off on this panel. Ask its admin for an invite.',
            default => null,
        };
    }

    /**
     * What the sign-up page says.
     *
     * @return array{mode: string, domains: list<string>}
     */
    public static function forPage(): array
    {
        return ['mode' => self::mode(), 'domains' => self::list('northdeck.signups.domains')];
    }

    public static function blocked(string $email): bool
    {
        return self::matches(self::domain($email), self::list('northdeck.signups.blocked_domains'));
    }

    public static function onAllowedDomain(string $email): bool
    {
        return self::matches(self::domain($email), self::list('northdeck.signups.domains'));
    }

    /**
     * A domain matches itself and its subdomains: acme.com covers eu.acme.com.
     *
     * @param  list<string>  $list
     */
    private static function matches(string $domain, array $list): bool
    {
        foreach ($list as $entry) {
            if ($domain === $entry || str_ends_with($domain, '.'.$entry)) {
                return true;
            }
        }

        return false;
    }

    private static function domain(string $email): string
    {
        return Str::lower(trim(Str::afterLast(trim($email), '@')));
    }

    private static function allowedList(): string
    {
        $domains = self::list('northdeck.signups.domains');

        return $domains === [] ? 'invited' : implode(', ', array_map(fn ($d) => '@'.$d, $domains));
    }

    /** @return list<string> */
    private static function list(string $key): array
    {
        $value = config($key, []);
        $value = is_string($value) ? PanelSettings::domains($value) : (is_array($value) ? $value : []);

        return array_values(array_filter(array_map(fn ($d) => is_string($d) ? ltrim(Str::lower(trim($d)), '@') : '', $value)));
    }
}
