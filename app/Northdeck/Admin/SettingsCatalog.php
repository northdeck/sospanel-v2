<?php

namespace App\Northdeck\Admin;

/**
 * The .env settings admins can change on /admin/settings, grouped as the page
 * shows them. Each field sets one or more config keys; `env` is the .env name
 * it goes over.
 *
 * What isn't here can't be changed while the panel runs, because the panel
 * needs it to start or to reach its own database: see NOT_MANAGED.
 */
final class SettingsCatalog
{
    /**
     * @return list<array{key: string, title: string, description: string, icon: string, fields: list<array{key: string, env: string, label: string, hint: string, type: string, config: list<string>, rules?: list<string>, options?: array<string, string>, min?: int, max?: int}>}>
     */
    public static function groups(): array
    {
        return [
            [
                'key' => 'panel',
                'title' => 'Panel',
                'description' => 'What people see in the browser tab, emails and sign-in pages.',
                'icon' => 'settings-fill',
                'fields' => [
                    ['key' => 'name', 'env' => 'APP_NAME', 'label' => 'Name', 'hint' => 'Shown across the panel and in emails.', 'type' => 'text', 'config' => ['app.name'], 'rules' => ['max:60']],
                ],
            ],
            [
                'key' => 'signups',
                'title' => 'New accounts',
                'description' => 'Who can make an account. People you invite from Users always can.',
                'icon' => 'user-fill',
                'fields' => [
                    ['key' => 'signups_mode', 'env' => 'NORTHDECK_SIGN_UPS', 'label' => 'Who can sign up', 'hint' => 'With sign-up off, only people you invite get in.', 'type' => 'select', 'config' => ['northdeck.signups.mode'], 'options' => [
                        'open' => 'Anyone',
                        'domains' => 'Only emails on the domains below',
                        'invite' => 'Only people with an invite',
                        'off' => 'Nobody: sign-up is off',
                    ]],
                    ['key' => 'signups_domains', 'env' => 'NORTHDECK_SIGN_UP_DOMAINS', 'label' => 'Allowed domains', 'hint' => 'For "only emails on the domains below". Comma-separated, like acme.com, acme.co.uk.', 'type' => 'list', 'config' => ['northdeck.signups.domains']],
                    ['key' => 'signups_blocked', 'env' => 'NORTHDECK_BLOCKED_DOMAINS', 'label' => 'Blocked domains', 'hint' => 'Never get a new account here, through any sign-in, unless you invite them.', 'type' => 'list', 'config' => ['northdeck.signups.blocked_domains']],
                    ['key' => 'signups_sso', 'env' => 'NORTHDECK_SSO_CREATES_ACCOUNTS', 'label' => 'SSO and SCIM make accounts', 'hint' => 'When off, workspace SSO and SCIM only sign in or add people who already have an account.', 'type' => 'bool', 'config' => ['northdeck.signups.sso']],
                ],
            ],
            [
                'key' => 'mail',
                'title' => 'Email',
                'description' => 'How the panel sends email, and the addresses it sends from.',
                'icon' => 'mail-fill',
                'fields' => [
                    ['key' => 'mail_mailer', 'env' => 'MAIL_MAILER', 'label' => 'Send with', 'hint' => 'Log writes emails to the log instead of sending them.', 'type' => 'select', 'config' => ['mail.default'], 'options' => [
                        'smtp' => 'SMTP', 'postmark' => 'Postmark', 'resend' => 'Resend', 'log' => 'Log (don’t send)',
                    ]],
                    ['key' => 'mail_host', 'env' => 'MAIL_HOST', 'label' => 'SMTP host', 'hint' => 'Like smtp.example.com.', 'type' => 'text', 'config' => ['mail.mailers.smtp.host'], 'rules' => ['max:255']],
                    ['key' => 'mail_port', 'env' => 'MAIL_PORT', 'label' => 'SMTP port', 'hint' => 'Usually 587, or 465 for TLS from the start.', 'type' => 'int', 'config' => ['mail.mailers.smtp.port'], 'min' => 1, 'max' => 65535],
                    ['key' => 'mail_scheme', 'env' => 'MAIL_SCHEME', 'label' => 'SMTP security', 'hint' => 'STARTTLS on 587 is picked by itself.', 'type' => 'select', 'config' => ['mail.mailers.smtp.scheme'], 'options' => [
                        'smtp' => 'SMTP (STARTTLS when offered)', 'smtps' => 'SMTPS (TLS from the start)',
                    ]],
                    ['key' => 'mail_username', 'env' => 'MAIL_USERNAME', 'label' => 'SMTP username', 'hint' => '', 'type' => 'text', 'config' => ['mail.mailers.smtp.username'], 'rules' => ['max:255']],
                    ['key' => 'mail_password', 'env' => 'MAIL_PASSWORD', 'label' => 'SMTP password', 'hint' => 'Stored encrypted. Never shown again.', 'type' => 'secret', 'config' => ['mail.mailers.smtp.password']],
                    ['key' => 'postmark_key', 'env' => 'POSTMARK_API_KEY', 'label' => 'Postmark server token', 'hint' => 'For "Send with Postmark".', 'type' => 'secret', 'config' => ['services.postmark.key']],
                    ['key' => 'resend_key', 'env' => 'RESEND_API_KEY', 'label' => 'Resend API key', 'hint' => 'For "Send with Resend".', 'type' => 'secret', 'config' => ['services.resend.key']],
                    ['key' => 'mail_from_address', 'env' => 'MAIL_FROM_ADDRESS', 'label' => 'Send from', 'hint' => 'The From address on every email.', 'type' => 'email', 'config' => ['mail.from.address']],
                    ['key' => 'mail_from_name', 'env' => 'MAIL_FROM_NAME', 'label' => 'Sender name', 'hint' => 'The name next to that address.', 'type' => 'text', 'config' => ['mail.from.name'], 'rules' => ['max:60']],
                ],
            ],
            [
                'key' => 'support',
                'title' => 'Support and links',
                'description' => 'Where people write for help, and where links point.',
                'icon' => 'help-fill',
                'fields' => [
                    ['key' => 'support_email', 'env' => 'NORTHDECK_SUPPORT_EMAIL', 'label' => 'Support email', 'hint' => 'Empty uses the send-from address.', 'type' => 'email', 'config' => ['northdeck.support.email', 'mail.support.address']],
                    ['key' => 'security_email', 'env' => 'NORTHDECK_SECURITY_EMAIL', 'label' => 'Security reports', 'hint' => 'A private address for vulnerability reports.', 'type' => 'email', 'config' => ['northdeck.support.security_email']],
                    ['key' => 'support_url', 'env' => 'NORTHDECK_SUPPORT_URL', 'label' => 'Help desk or docs', 'hint' => 'A page people can open for help.', 'type' => 'url', 'config' => ['northdeck.support.url']],
                    ['key' => 'chat_url', 'env' => 'NORTHDECK_CHAT_URL', 'label' => 'Community chat', 'hint' => 'Empty hides the chat link.', 'type' => 'url', 'config' => ['northdeck.support.chat_url', 'serveros.brand.discord_url']],
                    ['key' => 'website_url', 'env' => 'NORTHDECK_WEBSITE_URL', 'label' => 'Website', 'hint' => 'Northdeck’s website.', 'type' => 'url', 'config' => ['northdeck.brand.website']],
                    ['key' => 'source_url', 'env' => 'NORTHDECK_SOURCE_URL', 'label' => 'Source code', 'hint' => 'Where this build’s code lives.', 'type' => 'url', 'config' => ['northdeck.support.source_url', 'serveros.brand.source_url']],
                    ['key' => 'issues_url', 'env' => 'NORTHDECK_ISSUES_URL', 'label' => 'Report a problem', 'hint' => 'Where bug reports go.', 'type' => 'url', 'config' => ['northdeck.support.issues_url']],
                ],
            ],
            [
                'key' => 'signin',
                'title' => 'Sign-in options',
                'description' => 'Sign in with GitHub or Google, and the bot check on sign-up.',
                'icon' => 'key-fill',
                'fields' => [
                    ['key' => 'github_client_id', 'env' => 'GITHUB_CLIENT_ID', 'label' => 'GitHub client ID', 'hint' => 'From a GitHub OAuth app. Callback: /auth/github/callback.', 'type' => 'text', 'config' => ['services.github.client_id'], 'rules' => ['max:255']],
                    ['key' => 'github_client_secret', 'env' => 'GITHUB_CLIENT_SECRET', 'label' => 'GitHub client secret', 'hint' => 'Stored encrypted.', 'type' => 'secret', 'config' => ['services.github.client_secret']],
                    ['key' => 'google_client_id', 'env' => 'GOOGLE_CLIENT_ID', 'label' => 'Google client ID', 'hint' => 'From Google Cloud. Callback: /auth/google/callback.', 'type' => 'text', 'config' => ['services.google.client_id'], 'rules' => ['max:255']],
                    ['key' => 'google_client_secret', 'env' => 'GOOGLE_CLIENT_SECRET', 'label' => 'Google client secret', 'hint' => 'Stored encrypted.', 'type' => 'secret', 'config' => ['services.google.client_secret']],
                    ['key' => 'turnstile_site_key', 'env' => 'TURNSTILE_SITE_KEY', 'label' => 'Turnstile site key', 'hint' => 'Cloudflare’s bot check on the sign-up page. Empty turns it off.', 'type' => 'text', 'config' => ['services.turnstile.site_key'], 'rules' => ['max:255']],
                    ['key' => 'turnstile_secret_key', 'env' => 'TURNSTILE_SECRET_KEY', 'label' => 'Turnstile secret key', 'hint' => 'Stored encrypted.', 'type' => 'secret', 'config' => ['services.turnstile.secret_key']],
                ],
            ],
            [
                'key' => 'features',
                'title' => 'Features',
                'description' => 'Northdeck features you can turn on or off.',
                'icon' => 'layers-fill',
                'fields' => [
                    ['key' => 'sso', 'env' => 'NORTHDECK_SSO', 'label' => 'Single sign-on', 'hint' => 'Workspaces can sign in with their identity provider.', 'type' => 'bool', 'config' => ['northdeck.sso.enabled']],
                    ['key' => 'sso_private_hosts', 'env' => 'NORTHDECK_SSO_ALLOW_PRIVATE_HOSTS', 'label' => 'SSO on private networks', 'hint' => 'Lets identity providers on your LAN (a local Keycloak or authentik) be used.', 'type' => 'bool', 'config' => ['northdeck.sso.allow_private_hosts']],
                    ['key' => 'scim', 'env' => 'NORTHDECK_SCIM', 'label' => 'SCIM provisioning', 'hint' => 'A preview. Needs single sign-on on.', 'type' => 'bool', 'config' => ['northdeck.scim.enabled']],
                    ['key' => 'custom_roles', 'env' => 'NORTHDECK_CUSTOM_ROLES', 'label' => 'Custom roles', 'hint' => 'Workspaces can make roles with the permissions they pick.', 'type' => 'bool', 'config' => ['northdeck.roles.custom']],
                    ['key' => 'groq_key', 'env' => 'GROQ_API_KEY', 'label' => 'Groq API key', 'hint' => 'For Ask AI. Stored encrypted.', 'type' => 'secret', 'config' => ['services.groq.key']],
                ],
            ],
            [
                'key' => 'privacy',
                'title' => 'Updates and privacy',
                'description' => 'Where updates come from, and what the panel sends out.',
                'icon' => 'update-fill',
                'fields' => [
                    ['key' => 'auto_update', 'env' => 'NORTHDECK_AUTO_UPDATE', 'label' => 'Auto-update', 'hint' => 'Update by itself when the repository has a new commit. Backs up first.', 'type' => 'bool', 'config' => ['northdeck.updates.auto']],
                    ['key' => 'auto_update_hour', 'env' => 'NORTHDECK_AUTO_UPDATE_HOUR', 'label' => 'Auto-update at', 'hint' => 'The hour, 0 to 23 in the server’s time. The panel is down for a few minutes while it updates.', 'type' => 'int', 'config' => ['northdeck.updates.auto_hour'], 'min' => 0, 'max' => 23],
                    ['key' => 'update_repo', 'env' => 'NORTHDECK_UPDATE_REPO', 'label' => 'Update from', 'hint' => 'The GitHub repository, as owner/name.', 'type' => 'text', 'config' => ['northdeck.updates.repo'], 'rules' => ['max:200', 'regex:#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#']],
                    ['key' => 'update_branch', 'env' => 'NORTHDECK_UPDATE_BRANCH', 'label' => 'Branch', 'hint' => '', 'type' => 'text', 'config' => ['northdeck.updates.branch'], 'rules' => ['max:200', 'regex:#^[A-Za-z0-9_./-]+$#']],
                    ['key' => 'update_token', 'env' => 'NORTHDECK_UPDATE_TOKEN', 'label' => 'GitHub token', 'hint' => 'Only for a private repository. Stored encrypted.', 'type' => 'secret', 'config' => ['northdeck.updates.token']],
                    ['key' => 'telemetry', 'env' => 'SERVEROS_TELEMETRY', 'label' => 'Daily ping to serveros.com', 'hint' => 'Anonymous: install ID, version and a few counts.', 'type' => 'bool', 'config' => ['northdeck.telemetry']],
                    ['key' => 'update_feed', 'env' => 'SERVEROS_UPDATE_FEED_URL', 'label' => 'Upstream release feed', 'hint' => 'Where upstream’s updater looks for ServerOS releases. Northdeck’s build updates from the repository above instead.', 'type' => 'url', 'config' => ['serveros.updates.feed_url']],
                    ['key' => 'daemon_feed', 'env' => 'DAEMON_RELEASE_FEED_URL', 'label' => 'Daemon update feed', 'hint' => 'Where the panel looks for new serverosd versions.', 'type' => 'url', 'config' => ['daemon.release_feed_url']],
                ],
            ],
        ];
    }

    /**
     * Settings that stay in .env, and why.
     *
     * @var array<string, string>
     */
    public const array NOT_MANAGED = [
        'APP_KEY' => 'Encrypts everything, including what this page saves.',
        'APP_URL, SERVEROS_DOMAIN' => 'The address the panel runs on; the web server and certificates follow it.',
        'DB_*, REDIS_*' => 'The panel needs them to reach the database this page saves to.',
        'SESSION_*, CACHE_*, QUEUE_*' => 'Read before anything is loaded from the database.',
        'DAEMON_GATEWAY_*, DAEMON_CA_*' => 'Shared with the gateway container and every connected machine.',
        'APP_ENV, APP_DEBUG, LOG_*' => 'Set by the install; debug mode would show secrets on error pages.',
        'NORTHDECK_ADMIN' => 'Turning these pages off from these pages would lock you out.',
    ];

    /** @return array<string, array{key: string, env: string, label: string, hint: string, type: string, config: list<string>, rules?: list<string>, options?: array<string, string>, min?: int, max?: int}> */
    public static function fields(): array
    {
        $fields = [];
        foreach (self::groups() as $group) {
            foreach ($group['fields'] as $field) {
                $fields[$field['key']] = $field;
            }
        }

        return $fields;
    }
}
