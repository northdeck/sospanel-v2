<?php

use App\Models\User;
use App\Northdeck\Sso\Models\SsoIdentity;
use Illuminate\Testing\TestResponse;
use Tests\Support\Northdeck\FakeIdp;
use Tests\Support\Northdeck\SsoFixtures;

beforeEach(function () {
    $this->withoutVite();
    config()->set('northdeck.sso.enabled', true);
});

function samlPost(object $test, $connection, string $response): TestResponse
{
    $acs = $test->post(route('northdeck.sso.saml.acs', $connection), ['SAMLResponse' => $response]);
    $acs->assertStatus(303);

    return $test->get((string) $acs->headers->get('Location'));
}

test('SP metadata is served at a stable URL per connection', function () {
    $connection = SsoFixtures::saml(SsoFixtures::workspace());

    $response = $this->get(route('northdeck.sso.saml.metadata', $connection))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('samlmetadata+xml')
        ->and($response->getContent())->toContain('entityID="'.$connection->entityId().'"')
        ->and($response->getContent())->toContain($connection->acsUrl())
        ->and($connection->entityId())->toBe('https://serveros.test/sso/connections/'.$connection->uuid.'/saml/metadata');
});

test('an SP-initiated sign-in with a signed assertion signs the person in', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::saml($workspace);

    $start = $this->get(route('northdeck.sso.start', $connection))->assertRedirect();
    SsoFixtures::keepSession($this, $start);
    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
    $request = gzinflate(base64_decode((string) $query['SAMLRequest']));
    preg_match('/ID="([^"]+)"/', (string) $request, $id);
    expect($query)->toHaveKey('Signature');

    samlPost($this, $connection, FakeIdp::samlResponse(
        $connection->acsUrl(), $connection->entityId(), 'persistent-1', ['email' => ['grace@acme.test'], 'displayName' => ['Grace Hopper'], 'groups' => ['eng']], $id[1],
    ))->assertRedirect($workspace->url());

    $user = User::query()->where('email', 'grace@acme.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Grace Hopper')
        ->and(SsoIdentity::query()->where('subject', 'persistent-1')->value('user_id'))->toBe($user->id);
});

test('an IdP-initiated sign-in works when the connection allows it', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::saml($workspace);

    samlPost($this, $connection, FakeIdp::samlResponse($connection->acsUrl(), $connection->entityId(), 'p-2', ['email' => ['ada@acme.test']]))
        ->assertRedirect($workspace->url());
    $this->assertAuthenticated();
});

test('an IdP-initiated sign-in is refused when the connection turns it off', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::saml($workspace, ['allow_idp_initiated' => false]);

    samlPost($this, $connection, FakeIdp::samlResponse($connection->acsUrl(), $connection->entityId(), 'p-2', ['email' => ['ada@acme.test']]))
        ->assertRedirect(route('northdeck.sso.index'));
    $this->assertGuest();
});

test('an unsigned assertion is refused', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::saml($workspace);

    samlPost($this, $connection, FakeIdp::samlResponse($connection->acsUrl(), $connection->entityId(), 'p-3', ['email' => ['ada@acme.test']], sign: false))
        ->assertSessionHas('northdeck_sso_error', fn (string $m) => str_contains($m, 'didn’t check out'));
    $this->assertGuest();
});

test('an assertion signed by another key is refused', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::saml($workspace);

    samlPost($this, $connection, FakeIdp::samlResponse($connection->acsUrl(), $connection->entityId(), 'p-3', ['email' => ['ada@acme.test']], kid: 'attacker'))
        ->assertSessionHas('northdeck_sso_error', fn (string $m) => str_contains($m, 'didn’t check out'));
    $this->assertGuest();
});

test('an assertion for another service provider is refused', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::saml($workspace);

    samlPost($this, $connection, FakeIdp::samlResponse($connection->acsUrl(), 'https://other-sp.test', 'p-3', ['email' => ['ada@acme.test']]))
        ->assertSessionHas('northdeck_sso_error');
    $this->assertGuest();
});

test('an expired assertion is refused', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::saml($workspace);

    samlPost($this, $connection, FakeIdp::samlResponse($connection->acsUrl(), $connection->entityId(), 'p-3', ['email' => ['ada@acme.test']], notOnOrAfter: -600))
        ->assertSessionHas('northdeck_sso_error');
    $this->assertGuest();
});

test('the same assertion can’t be posted twice', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::saml($workspace);
    $response = FakeIdp::samlResponse($connection->acsUrl(), $connection->entityId(), 'p-4', ['email' => ['ada@acme.test']]);

    samlPost($this, $connection, $response)->assertRedirect($workspace->url());
    auth()->logout();

    samlPost($this, $connection, $response)->assertSessionHas('northdeck_sso_error', 'This SAML response was already used.');
    $this->assertGuest();
});

test('a response to a sign-in this browser didn’t start is refused', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::saml($workspace);

    samlPost($this, $connection, FakeIdp::samlResponse($connection->acsUrl(), $connection->entityId(), 'p-5', ['email' => ['ada@acme.test']], '_someone_elses_request'))
        ->assertRedirect(route('northdeck.sso.index'));
    $this->assertGuest();
});
