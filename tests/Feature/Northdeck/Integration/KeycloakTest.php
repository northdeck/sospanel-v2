<?php

/*
 * Sign-ins against a real identity provider: Keycloak, over OpenID Connect
 * and SAML 2.0. CI starts it (.github/workflows/northdeck-sso.yml) with
 * keycloak-realm.json and sets KEYCLOAK_URL; without it these are skipped.
 *
 * The "browser" is Guzzle with a cookie jar, so the identity provider's own
 * login page, cookies, redirects and signatures are all real. The panel's
 * side runs in the test, so its callback is called with what Keycloak sent.
 */

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Northdeck\Sso\Models\SsoConnection;
use App\Northdeck\Sso\Models\SsoGroupMapping;
use App\Northdeck\Sso\Saml\SamlService;
use App\Northdeck\Sso\Support\IdpHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\Northdeck\SsoFixtures;

const KEYCLOAK_SAML_CONNECTION = '00000000-0000-4000-8000-000000000001';

beforeEach(function () {
    if (! filled(env('KEYCLOAK_URL'))) {
        $this->markTestSkipped('KEYCLOAK_URL is not set; CI runs these against a real Keycloak.');
    }

    $this->withoutVite();
    config()->set('northdeck.sso.enabled', true);
    // Keycloak runs on 127.0.0.1 in CI.
    config()->set('northdeck.sso.allow_private_hosts', true);
    IdpHttp::$resolver = null;
});

function keycloak(): string
{
    return rtrim((string) env('KEYCLOAK_URL'), '/').'/realms/northdeck';
}

function browser(): Client
{
    return new Client(['cookies' => new CookieJar, 'allow_redirects' => false, 'http_errors' => false, 'timeout' => 20]);
}

/** Opens Keycloak's login page and signs in as ada. */
function keycloakLogin(Client $browser, string $url): ResponseInterface
{
    $page = (string) $browser->get($url)->getBody();
    expect(preg_match('/<form[^>]+id="kc-form-login"[^>]+action="([^"]+)"/', $page, $m))->toBe(1, 'Keycloak’s login form');

    return $browser->post(html_entity_decode($m[1]), ['form_params' => [
        'username' => 'ada',
        'password' => 'correct-horse-battery',
        'credentialId' => '',
    ]]);
}

/** Path and query of a URL on the panel, for the test client. */
function onPanel(string $url): string
{
    $parts = parse_url($url);
    expect($parts['host'] ?? null)->toBe('serveros.test');

    return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
}

test('OpenID Connect against Keycloak: discovery, PKCE, a signed ID token, groups to roles', function () {
    $workspace = SsoFixtures::workspace('acme.test');
    $connection = new SsoConnection([
        'workspace_id' => $workspace->id, 'name' => 'Keycloak', 'protocol' => 'oidc', 'preset' => 'keycloak', 'enabled' => true,
        'settings' => ['issuer' => keycloak(), 'client_id' => 'panel', 'scopes' => 'openid email profile', 'groups_claim' => 'groups'],
        'jit' => true, 'default_role' => 'viewer',
    ]);
    $connection->client_secret = 'panel-secret';
    $connection->save();
    $connection->domains()->sync($workspace->emailDomains()->pluck('id')->all());
    SsoGroupMapping::query()->create(['connection_id' => $connection->id, 'group' => 'platform', 'role' => 'admin']);

    $start = $this->get(route('northdeck.sso.start', $connection))->assertRedirect();
    SsoFixtures::keepSession($this, $start);

    $answer = keycloakLogin(browser(), (string) $start->headers->get('Location'));
    expect($answer->getStatusCode())->toBe(302);
    $callback = $answer->getHeaderLine('Location');
    expect($callback)->toStartWith('https://serveros.test/sso/callback?');

    $this->get(onPanel($callback))->assertRedirect($workspace->url());

    $user = User::query()->where('email', 'ada@acme.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Ada Lovelace')
        ->and($workspace->roleOf($user))->toBe(WorkspaceRole::Admin);
});

test('SAML 2.0 against Keycloak: metadata, a signed assertion, groups to roles', function () {
    $workspace = SsoFixtures::workspace('acme.test');
    $pair = SamlService::newKeyPair('serveros.test');
    $idp = SamlService::fetchIdpMetadata(keycloak().'/protocol/saml/descriptor');

    $connection = new SsoConnection([
        'uuid' => KEYCLOAK_SAML_CONNECTION,
        'workspace_id' => $workspace->id, 'name' => 'Keycloak SAML', 'protocol' => 'saml', 'preset' => 'saml', 'enabled' => true,
        'settings' => [...$idp, 'groups_claim' => 'groups', 'name_attribute' => 'givenName'],
        'jit' => true, 'default_role' => 'viewer',
    ]);
    $connection->sp_private_key = $pair['key'];
    $connection->sp_certificate = $pair['certificate'];
    $connection->save();
    $connection->domains()->sync($workspace->emailDomains()->pluck('id')->all());
    SsoGroupMapping::query()->create(['connection_id' => $connection->id, 'group' => 'platform', 'role' => 'admin']);

    $start = $this->get(route('northdeck.sso.start', $connection))->assertRedirect();
    SsoFixtures::keepSession($this, $start);

    $answer = keycloakLogin(browser(), (string) $start->headers->get('Location'));
    $page = (string) $answer->getBody();
    expect(preg_match('/name="SAMLResponse" value="([^"]+)"/', $page, $response))->toBe(1, 'Keycloak posts a SAMLResponse')
        ->and(preg_match('/<form[^>]+action="([^"]+)"/', $page, $action))->toBe(1);
    expect(html_entity_decode($action[1]))->toBe($connection->acsUrl());
    preg_match('/name="RelayState" value="([^"]*)"/', $page, $relay);

    $acs = $this->post(onPanel($connection->acsUrl()), [
        'SAMLResponse' => html_entity_decode($response[1]),
        'RelayState' => html_entity_decode($relay[1] ?? ''),
    ])->assertStatus(303);
    $this->get(onPanel((string) $acs->headers->get('Location')))->assertRedirect($workspace->url());

    $user = User::query()->where('email', 'ada@acme.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($workspace->roleOf($user))->toBe(WorkspaceRole::Admin);
});
