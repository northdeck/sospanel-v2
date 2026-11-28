<?php

// Northdeck-only settings. Nothing here exists upstream; see README.md,
// "Northdeck-specific changes", and docs/sso.md.

return [

    'brand' => [
        'name' => env('NORTHDECK_BRAND_NAME', 'Northdeck'),
        'website' => env('NORTHDECK_WEBSITE_URL', 'https://northdeck.example'),
    ],

    'support' => [
        // TODO(northdeck): set the real support address. Until it is set the
        // panel falls back to MAIL_FROM_ADDRESS, like upstream does.
        'email' => env('NORTHDECK_SUPPORT_EMAIL'),
        // TODO(northdeck): set the help desk or docs URL.
        'url' => env('NORTHDECK_SUPPORT_URL'),
        // TODO(northdeck): set a private address for security reports.
        'security_email' => env('NORTHDECK_SECURITY_EMAIL'),
        'source_url' => env('NORTHDECK_SOURCE_URL', 'https://github.com/northdeck/sospanel-v2'),
        'issues_url' => env('NORTHDECK_ISSUES_URL', 'https://github.com/northdeck/sospanel-v2/issues/new'),
        // Empty hides the community chat link.
        'chat_url' => env('NORTHDECK_CHAT_URL'),
        // Upstream asks self-hosters to donate to ServerOS or buy its
        // license. That ask isn't Northdeck's to make, so it's hidden.
        'hide_upstream_ask' => (bool) env('NORTHDECK_HIDE_UPSTREAM_ASK', true),
    ],

    // The anonymous daily ping to serveros.com (InstallPing). Northdeck's
    // build sends it only if SERVEROS_TELEMETRY=true; upstream sends it
    // unless it's false.
    'telemetry' => (bool) env('SERVEROS_TELEMETRY', false),

    'sso' => [
        'enabled' => (bool) env('NORTHDECK_SSO', true),

        // Seconds of clock difference allowed when checking ID tokens. SAML
        // uses php-saml's fixed allowance (180 seconds).
        'clock_skew' => (int) env('NORTHDECK_SSO_CLOCK_SKEW', 120),

        // How long discovery documents and signing keys are cached. An ID
        // token signed with a key we haven't seen fetches the keys again.
        'cache_ttl' => (int) env('NORTHDECK_SSO_CACHE_TTL', 3600),

        'http_timeout' => (int) env('NORTHDECK_SSO_HTTP_TIMEOUT', 10),

        // Identity providers on a private network (an authentik or Keycloak
        // on your LAN). Off by default, so a workspace admin can't point the
        // panel at internal addresses.
        'allow_private_hosts' => (bool) env('NORTHDECK_SSO_ALLOW_PRIVATE_HOSTS', false),

        // An optional connection for the whole install, set here instead of
        // in a workspace's settings. Its domains are trusted as given.
        'instance' => [
            'protocol' => env('NORTHDECK_SSO_PROTOCOL'), // oidc, saml or empty for none
            'name' => env('NORTHDECK_SSO_NAME', 'Single sign-on'),
            'preset' => env('NORTHDECK_SSO_PRESET', 'oidc'),
            'workspace' => env('NORTHDECK_SSO_WORKSPACE'), // slug people join; empty means sign-in only
            'domains' => env('NORTHDECK_SSO_DOMAINS', ''), // comma-separated
            'jit' => (bool) env('NORTHDECK_SSO_JIT', true),
            'default_role' => env('NORTHDECK_SSO_DEFAULT_ROLE', 'member'),
            'groups_claim' => env('NORTHDECK_SSO_GROUPS_CLAIM', 'groups'),
            'oidc' => [
                'issuer' => env('NORTHDECK_SSO_OIDC_ISSUER'),
                'client_id' => env('NORTHDECK_SSO_OIDC_CLIENT_ID'),
                'client_secret' => env('NORTHDECK_SSO_OIDC_CLIENT_SECRET'),
                'scopes' => env('NORTHDECK_SSO_OIDC_SCOPES', 'openid email profile'),
            ],
            'saml' => [
                'metadata_url' => env('NORTHDECK_SSO_SAML_METADATA_URL'),
                'idp_entity_id' => env('NORTHDECK_SSO_SAML_IDP_ENTITY_ID'),
                'idp_sso_url' => env('NORTHDECK_SSO_SAML_IDP_SSO_URL'),
                'idp_slo_url' => env('NORTHDECK_SSO_SAML_IDP_SLO_URL'),
                'idp_certificate' => env('NORTHDECK_SSO_SAML_IDP_CERT'),
            ],
        ],
    ],

    'signups' => [
        // Who can make an account: open (anyone), domains (only emails on
        // `domains`), invite (people with an invite; upstream's "closed") or
        // off (only people an instance admin invites). Defaults to upstream's
        // SERVEROS_SIGN_UPS. Admins can change all of these on /admin/settings.
        'mode' => env('NORTHDECK_SIGN_UPS') ?: (env('SERVEROS_SIGN_UPS', 'closed') === 'open' ? 'open' : 'invite'),
        // Comma-separated, e.g. "acme.com, acme.co.uk".
        'domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('NORTHDECK_SIGN_UP_DOMAINS', ''))))),
        // Never get a new account, whatever the mode (an admin invite still works).
        'blocked_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('NORTHDECK_BLOCKED_DOMAINS', ''))))),
        // Whether SSO and SCIM may make new accounts. They still follow the
        // blocked domains.
        'sso' => (bool) env('NORTHDECK_SSO_CREATES_ACCOUNTS', true),
        // Days an admin invite lasts.
        'invite_days' => (int) env('NORTHDECK_INVITE_DAYS', 14),
    ],

    'updates' => [
        // Northdeck's build updates from its own repository, not upstream's
        // releases: the panel checks this branch for new commits, and the
        // server's updater (serveros-panel) builds and restarts on them.
        'repo' => env('NORTHDECK_UPDATE_REPO', 'northdeck/sospanel-v2'),
        'branch' => env('NORTHDECK_UPDATE_BRANCH', 'main'),
        // Only for a private repository: a GitHub token that can read it.
        'token' => env('NORTHDECK_UPDATE_TOKEN'),
        // Update by itself when there's a new commit.
        'auto' => (bool) env('NORTHDECK_AUTO_UPDATE', true),
        // The hour (0-23, server time) auto-updates run; empty: as soon as one's found.
        'auto_hour' => is_numeric($hour = env('NORTHDECK_AUTO_UPDATE_HOUR', 4)) ? (int) $hour : null,
    ],

    'roles' => [
        // Workspace roles with hand-picked permissions (Settings › Members).
        // Upstream sells these; Northdeck's build has them on.
        'custom' => (bool) env('NORTHDECK_CUSTOM_ROLES', true),
    ],

    'admin' => [
        // The /admin pages for instance admins (php artisan admin:grant).
        // Values saved there override the env values above.
        'enabled' => (bool) env('NORTHDECK_ADMIN', true),
    ],

    'scim' => [
        // SCIM 2.0 provisioning. A preview: off unless NORTHDECK_SCIM is set.
        'enabled' => (bool) env('NORTHDECK_SCIM', false),
    ],

];
