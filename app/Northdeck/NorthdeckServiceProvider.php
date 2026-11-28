<?php

namespace App\Northdeck;

use App\Models\User;
use App\Models\Workspace;
use App\Northdeck\Admin\AdminArea;
use App\Northdeck\Admin\PanelInvite;
use App\Northdeck\Admin\PanelSettings;
use App\Northdeck\Admin\SignUpPolicy;
use App\Northdeck\Sso\Http\Middleware\RefusePasswordWhenSsoRequired;
use App\Northdeck\Sso\Http\Middleware\RequireWorkspaceSso;
use App\Northdeck\Sso\InstanceConnection;
use App\Northdeck\Sso\Models\SsoConnection;
use App\Northdeck\Sso\NorthdeckEntitlements;
use App\Northdeck\Sso\Routes;
use App\Northdeck\Sso\SsoSignInHooks;
use App\Northdeck\Sso\Support\SsoUrls;
use App\Support\Entitlements\Entitlements;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\SettingsItem;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

/**
 * Everything Northdeck adds to the panel plugs in here, through the panel's
 * own extension points (docs/extensions.md), so upstream files stay as they
 * are. See README.md, "Northdeck-specific changes".
 */
final class NorthdeckServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('northdeck.php'), 'northdeck');

        $this->app->extend(Entitlements::class, fn (Entitlements $inner) => new NorthdeckEntitlements($inner));

        // Northdeck's Vue pages live in resources/js/northdeck/pages (the
        // browser finds them through resources/js/lib/pages.ts).
        $config = $this->app->make('config');
        $paths = (array) $config->get('inertia.pages.paths', [resource_path('js/pages')]);
        $config->set('inertia.pages.paths', [...$paths, resource_path('js/northdeck/pages')]);
    }

    public function boot(): void
    {
        // What admins saved on /admin/panel goes over the env first.
        PanelSettings::apply($this->app->make('config'));
        self::applyDefaults($this->app->make('config'));

        $extensions = $this->app->make(Extensions::class);
        AdminArea::register($extensions);

        // An admin invite is used once its email has an account.
        User::created(fn (User $user) => rescue(fn () => PanelInvite::accept($user), report: false));

        $extensions->routes(Extensions::SIGN_IN, Routes::signIn(...));
        $extensions->routes(Extensions::WORKSPACE, Routes::workspace(...));
        $extensions->routes(Extensions::API, Routes::api(...));
        $extensions->addMiddleware(Extensions::PASSWORD_SIGN_IN, RefusePasswordWhenSsoRequired::class);
        $extensions->addMiddleware(Extensions::WORKSPACE_SIGN_IN, RequireWorkspaceSso::class);
        $extensions->addSignInHooks(new SsoSignInHooks);
        $extensions->addAuditTarget('sso_connection', SsoConnection::class, fn (SsoConnection $c) => $c->name);

        $extensions->addSettingsItem(new SettingsItem(
            key: 'sso',
            label: 'Single sign-on',
            icon: 'lock',
            path: '/settings/sso',
            group: 'workspace',
            component: 'workspace/settings/Sso',
            keywords: 'sso saml oidc openid okta entra azure google keycloak authentik onelogin jumpcloud auth0 adfs',
            visible: fn (Workspace $workspace) => $workspace->hasSso(),
            description: 'Sign in with your identity provider.',
        ));

        // Sharing a panel link shows the Northdeck banner. Status pages set
        // their own image, so they're left alone.
        $extensions->addHeadTag(fn (array $page) => ($page['component'] ?? null) === 'status/Show' ? null
            : '<meta property="og:image" content="'.e(asset('northdeck/banner.png')).'">'
            .'<meta property="og:site_name" content="'.e((string) config('app.name')).'">'
            .'<meta name="twitter:card" content="summary_large_image">');

        // With sign-up off, the sign-in page drops "Create an account".
        $extensions->addPageProps('auth/Login', fn () => SignUpPolicy::mode() === 'off' ? ['northdeckSignUpOff' => true] : []);

        // The sign-in page asks whether to show "Sign in with SSO".
        $extensions->addSharedProps(fn (Request $request) => [
            'northdeck' => [
                'brand' => config('northdeck.brand.name'),
                'sso' => (bool) config('northdeck.sso.enabled'),
                'ssoUrl' => (bool) config('northdeck.sso.enabled') ? SsoUrls::route('northdeck.sso.index') : null,
                'instanceSso' => (bool) config('northdeck.sso.enabled') && InstanceConnection::configured()
                    ? (string) config('northdeck.sso.instance.name')
                    : null,
                'support' => [
                    'email' => config('mail.support.address'),
                    'url' => config('northdeck.support.url'),
                ],
            ],
        ]);
    }

    /**
     * Support and source links point at Northdeck, not upstream. Values set
     * in .env for the upstream keys still win.
     */
    public static function applyDefaults(Repository $config): void
    {
        // Upstream's own switch: open, or closed for every other mode
        // (SignUpPolicy adds Northdeck's rules on top).
        $config->set('serveros.sign_ups', $config->get('northdeck.signups.mode') === 'open' ? 'open' : 'closed');

        if (filled($email = $config->get('northdeck.support.email'))) {
            $config->set('mail.support.address', $email);
        }
        // Only where they still hold upstream's defaults, so a value set for
        // the upstream key in .env wins (and config:cache keeps working).
        $defaults = [
            'serveros.brand.source_url' => ['https://github.com/serveroshq/panel', 'northdeck.support.source_url'],
            'serveros.brand.discord_url' => ['https://discord.gg/serveros', 'northdeck.support.chat_url'],
        ];
        foreach ($defaults as $key => [$upstream, $ours]) {
            if ($config->get($key) === $upstream) {
                $config->set($key, $config->get($ours));
            }
        }

        $config->set('serveros.telemetry.enabled', (bool) $config->get('northdeck.telemetry'));

        if ((bool) $config->get('northdeck.support.hide_upstream_ask')) {
            $config->set('serveros.support_ask.enabled', false);
        }
        if (blank($config->get('serveros.brand.website'))) {
            $config->set('serveros.brand.website', $config->get('northdeck.brand.name'));
        }
    }
}
