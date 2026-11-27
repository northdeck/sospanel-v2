<?php

use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\User;
use App\Northdeck\Sso\Models\SsoIdentity;
use App\Northdeck\Sso\Oidc\OidcClient;
use App\Northdeck\Sso\SsoSignIn;
use Illuminate\Testing\TestResponse;
use Tests\Support\Northdeck\FakeIdp;
use Tests\Support\Northdeck\SsoFixtures;

beforeEach(function () {
    $this->withoutVite();
    config()->set('northdeck.sso.enabled', true);
    config()->set('northdeck.sso.allow_private_hosts', true);
    FakeIdp::$signingKid = 'key-1';
    FakeIdp::$nextClaims = null;
    FakeIdp::oidc();
});

/** Starts a sign-in and answers it as the provider would. */
function oidcSignIn(object $test, $connection, array $claims, array $override = []): TestResponse
{
    $start = $test->get(route('northdeck.sso.start', $connection));
    $start->assertRedirect();
    SsoFixtures::keepSession($test, $start);
    $params = FakeIdp::authorizeParams((string) $start->headers->get('Location'));
    FakeIdp::$nextClaims = ['nonce' => $params['nonce'], ...$claims];

    return $test->get(route('northdeck.sso.oidc.callback', ['state' => $override['state'] ?? $params['state'], 'code' => 'the-code']));
}

test('a new person signs in, gets an account and joins the workspace', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);

    $start = $this->get(route('northdeck.sso.start', $connection));
    $params = FakeIdp::authorizeParams((string) $start->headers->get('Location'));
    expect($params['query'])->toMatchArray([
        'client_id' => FakeIdp::CLIENT_ID,
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
        'redirect_uri' => 'https://serveros.test/sso/callback',
    ])->and($params['state'])->not->toBe('')->and($params['nonce'])->not->toBe('');

    SsoFixtures::keepSession($this, $start);
    FakeIdp::$nextClaims = ['sub' => 'okta|1', 'email' => 'ada@acme.test', 'email_verified' => true, 'name' => 'Ada Lovelace', 'nonce' => $params['nonce']];

    $this->get(route('northdeck.sso.oidc.callback', ['state' => $params['state'], 'code' => 'the-code']))
        ->assertRedirect($workspace->url());

    $user = User::query()->where('email', 'ada@acme.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($workspace->roleOf($user))->toBe(WorkspaceRole::Member)
        ->and(SsoIdentity::query()->where('connection_id', $connection->id)->where('subject', 'okta|1')->value('user_id'))->toBe($user->id)
        ->and(session(SsoSignIn::SESSION)['connection'])->toBe($connection->uuid)
        ->and(Activity::query()->where('action', 'sso.sign_in')->exists())->toBeTrue();

    // PKCE: the verifier sent to the token endpoint matches the challenge.
    expect(OidcClient::challenge(FakeIdp::$tokenRequests[0]['code_verifier']))->toBe($params['query']['code_challenge']);
});

test('the state has to match a sign-in this browser started', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);

    oidcSignIn($this, $connection, ['sub' => 's1', 'email' => 'ada@acme.test', 'email_verified' => true], ['state' => 'forged'])
        ->assertRedirect(route('northdeck.sso.index'));

    $this->assertGuest();
});

test('a state can only be used once', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $response = oidcSignIn($this, $connection, ['sub' => 's1', 'email' => 'ada@acme.test', 'email_verified' => true]);
    $response->assertRedirect($workspace->url());
    auth()->logout();

    $state = FakeIdp::authorizeParams((string) $this->get(route('northdeck.sso.start', $connection))->headers->get('Location'))['state'];
    $this->get(route('northdeck.sso.oidc.callback', ['state' => $state, 'code' => 'x']));
    $this->get(route('northdeck.sso.oidc.callback', ['state' => $state, 'code' => 'x']))->assertRedirect(route('northdeck.sso.index'));
});

test('an ID token with the wrong nonce is refused', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);

    oidcSignIn($this, $connection, ['sub' => 's1', 'email' => 'ada@acme.test', 'email_verified' => true, 'nonce' => 'not-it'])
        ->assertRedirect(route('northdeck.sso.index'))
        ->assertSessionHas('northdeck_sso_error', 'The ID token doesn’t belong to this sign-in.');

    $this->assertGuest();
    expect(Activity::query()->where('action', 'sso.sign_in_failed')->exists())->toBeTrue();
});

test('an ID token for another app or issuer, or an expired one, is refused', function (array $claims, string $error) {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);

    oidcSignIn($this, $connection, ['sub' => 's1', 'email' => 'ada@acme.test', 'email_verified' => true, ...$claims])
        ->assertSessionHas('northdeck_sso_error', fn (string $message) => str_contains($message, $error));

    $this->assertGuest();
})->with([
    'audience' => [['aud' => 'someone-else'], 'different app'],
    'issuer' => [['iss' => 'https://evil.test'], 'different issuer'],
    'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200], 'didn’t check out'],
]);

test('an ID token signed with a key the provider never published is refused', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    FakeIdp::$signingKid = 'rogue';

    oidcSignIn($this, $connection, ['sub' => 's1', 'email' => 'ada@acme.test', 'email_verified' => true])
        ->assertSessionHas('northdeck_sso_error', fn (string $m) => str_contains($m, 'didn’t check out'));
    $this->assertGuest();
});

test('a rotated signing key is fetched again from the JWKS', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);

    // Cache the old key set, then rotate to a new key.
    oidcSignIn($this, $connection, ['sub' => 's1', 'email' => 'ada@acme.test', 'email_verified' => true])->assertRedirect($workspace->url());
    auth()->logout();

    FakeIdp::oidc(['key-1', 'key-2']);
    FakeIdp::$signingKid = 'key-2';

    oidcSignIn($this, $connection, ['sub' => 's1', 'email' => 'ada@acme.test', 'email_verified' => true])->assertRedirect($workspace->url());
    $this->assertAuthenticated();
});

test('Google Workspace only lets in accounts on the hosted domain', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace, ['preset' => 'google'], ['hosted_domain' => 'acme.test']);

    $start = $this->get(route('northdeck.sso.start', $connection));
    expect(FakeIdp::authorizeParams((string) $start->headers->get('Location'))['query']['hd'])->toBe('acme.test');

    oidcSignIn($this, $connection, ['sub' => 'g1', 'email' => 'ada@acme.test', 'email_verified' => true, 'hd' => 'other.test'])
        ->assertSessionHas('northdeck_sso_error', 'Sign in with your acme.test Google account.');
    $this->assertGuest();
});

test('Entra ID keys people on tid and oid, and holds them to the tenant', function () {
    $tenant = '11111111-2222-3333-4444-555555555555';
    $issuer = "https://login.microsoftonline.com/{$tenant}/v2.0";
    FakeIdp::oidc(['key-1'], $issuer);

    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace, ['preset' => 'entra'], ['tenant' => $tenant, 'issuer' => '']);

    oidcSignIn($this, $connection, ['iss' => $issuer, 'tid' => $tenant, 'oid' => 'oid-42', 'sub' => 'pairwise-sub', 'email' => 'ada@acme.test'])
        ->assertRedirect($workspace->url());

    expect(SsoIdentity::query()->value('subject'))->toBe("{$tenant}/oid-42");
});

test('the client secret goes to the token endpoint but never to the page', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);

    expect($connection->toPageProps())->not->toHaveKey('client_secret')
        ->and(json_encode($connection->toPageProps()))->not->toContain('top-secret')
        ->and(json_encode($connection->toArray()))->not->toContain('top-secret')
        ->and($connection->getRawOriginal('client_secret'))->not->toBe('top-secret');
});
