<?php

namespace App\Northdeck\Sso;

/**
 * Presets only fill in the form and point at the right part of docs/sso.md.
 * Every one of them runs on the same generic OIDC or SAML code.
 */
final class Presets
{
    /**
     * @return array<string, array{label: string, protocol: string, issuer: string, hint: string, docs: string, scopes?: string, groups_claim?: string}>
     */
    public static function all(): array
    {
        return [
            'google' => [
                'label' => 'Google Workspace',
                'protocol' => 'oidc',
                'issuer' => 'https://accounts.google.com',
                'hint' => 'Set the hosted domain so only accounts on it can sign in.',
                'docs' => 'google-workspace',
            ],
            'entra' => [
                'label' => 'Microsoft Entra ID',
                'protocol' => 'oidc',
                'issuer' => 'https://login.microsoftonline.com/{tenant}/v2.0',
                'hint' => 'Use your tenant ID. "organizations" lets any work account in, so only use it with care.',
                'docs' => 'microsoft-entra-id',
                'groups_claim' => 'groups',
            ],
            'okta' => [
                'label' => 'Okta',
                'protocol' => 'oidc',
                'issuer' => 'https://your-org.okta.com',
                'hint' => 'The issuer is your Okta domain, or an authorization server like https://your-org.okta.com/oauth2/default.',
                'docs' => 'okta',
                'scopes' => 'openid email profile groups',
            ],
            'onelogin' => [
                'label' => 'OneLogin',
                'protocol' => 'oidc',
                'issuer' => 'https://your-org.onelogin.com/oidc/2',
                'hint' => 'The issuer is on the app’s SSO tab.',
                'docs' => 'onelogin',
                'scopes' => 'openid email profile groups',
            ],
            'jumpcloud' => [
                'label' => 'JumpCloud',
                'protocol' => 'oidc',
                'issuer' => 'https://oauth.id.jumpcloud.com/',
                'hint' => 'Add the email and groups attributes on the app.',
                'docs' => 'jumpcloud',
            ],
            'keycloak' => [
                'label' => 'Keycloak',
                'protocol' => 'oidc',
                'issuer' => 'https://keycloak.example.com/realms/your-realm',
                'hint' => 'The issuer is the realm’s URL.',
                'docs' => 'keycloak',
            ],
            'authentik' => [
                'label' => 'authentik',
                'protocol' => 'oidc',
                'issuer' => 'https://authentik.example.com/application/o/your-app/',
                'hint' => 'The issuer is on the provider’s page, ending in a slash.',
                'docs' => 'authentik',
            ],
            'auth0' => [
                'label' => 'Auth0',
                'protocol' => 'oidc',
                'issuer' => 'https://your-tenant.auth0.com/',
                'hint' => 'The issuer is your tenant’s domain, ending in a slash.',
                'docs' => 'auth0',
            ],
            'oidc' => [
                'label' => 'Other OpenID Connect',
                'protocol' => 'oidc',
                'issuer' => '',
                'hint' => 'Any provider with a /.well-known/openid-configuration document.',
                'docs' => 'generic-openid-connect',
            ],
            'saml' => [
                'label' => 'SAML 2.0',
                'protocol' => 'saml',
                'issuer' => '',
                'hint' => 'ADFS, Entra ID, Okta, OneLogin, JumpCloud or any SAML 2.0 provider.',
                'docs' => 'generic-saml',
            ],
        ];
    }

    /** @return array{label: string, protocol: string, issuer: string, hint: string, docs: string, scopes?: string, groups_claim?: string}|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return list<array<string, string>> */
    public static function toPageProps(): array
    {
        return array_map(
            fn (string $key, array $preset) => ['key' => $key, ...$preset],
            array_keys(self::all()),
            self::all(),
        );
    }
}
