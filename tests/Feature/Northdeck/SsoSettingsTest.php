<?php

use App\Models\Activity;
use App\Models\WorkspaceEmailDomain;
use App\Northdeck\Sso\Models\SsoConnection;
use Tests\Support\Northdeck\FakeIdp;
use Tests\Support\Northdeck\SsoFixtures;

beforeEach(function () {
    $this->withoutVite();
    config()->set('northdeck.sso.enabled', true);
    config()->set('northdeck.sso.allow_private_hosts', true);
    FakeIdp::oidc();
});

test('an admin adds an OIDC connection; the secret is encrypted and never sent back', function () {
    $workspace = SsoFixtures::workspace();
    $domain = WorkspaceEmailDomain::query()->where('workspace_id', $workspace->id)->firstOrFail();

    $this->actingAs($workspace->owner)->post($workspace->url('/settings/sso/connections'), [
        'preset' => 'okta',
        'name' => 'Okta',
        'default_role' => 'member',
        'domain_ids' => [$domain->id],
        'settings' => ['issuer' => FakeIdp::ISSUER, 'client_id' => FakeIdp::CLIENT_ID, 'scopes' => 'openid email profile groups'],
        'client_secret' => 's3cret-value',
    ])->assertSessionHasNoErrors();

    $connection = SsoConnection::query()->firstOrFail();
    expect($connection->client_secret)->toBe('s3cret-value')
        ->and($connection->getRawOriginal('client_secret'))->not->toContain('s3cret-value')
        ->and($connection->verifiedDomains())->toBe(['acme.test'])
        ->and(Activity::query()->where('action', 'sso.connection_create')->exists())->toBeTrue();

    $this->actingAs($workspace->owner)->get($workspace->url('/settings/sso'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspace/settings/Sso')
            ->where('connections.0.hasClientSecret', true)
            ->where('connections.0.urls.0.url', 'https://serveros.test/sso/callback')
            ->missing('connections.0.client_secret'))
        ->assertDontSee('s3cret-value');
});

test('an issuer without a discovery document is refused', function () {
    $workspace = SsoFixtures::workspace();
    Http::fake(['https://nope.test/*' => Http::response('', 404)]);

    $this->actingAs($workspace->owner)->post($workspace->url('/settings/sso/connections'), [
        'preset' => 'oidc', 'name' => 'Nope', 'default_role' => 'member',
        'settings' => ['issuer' => 'https://nope.test', 'client_id' => 'x'],
    ])->assertSessionHasErrors('settings');

    expect(SsoConnection::query()->count())->toBe(0);
});

test('a SAML connection gets its own signing certificate and reads the IdP metadata XML', function () {
    $workspace = SsoFixtures::workspace();
    $cert = preg_replace('/-----[^-]+-----|\s+/', '', FakeIdp::key('saml-1')['cert']);
    $xml = <<<XML
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://idp.test/saml">
  <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
    <md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:X509Data><ds:X509Certificate>{$cert}</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>
    <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.test/saml/sso"/>
  </md:IDPSSODescriptor>
</md:EntityDescriptor>
XML;

    $this->actingAs($workspace->owner)->post($workspace->url('/settings/sso/connections'), [
        'preset' => 'saml', 'name' => 'ADFS', 'default_role' => 'viewer', 'idp_metadata_xml' => $xml,
    ])->assertSessionHasNoErrors();

    $connection = SsoConnection::query()->firstOrFail();
    expect($connection->stringSetting('idp_entity_id'))->toBe('https://idp.test/saml')
        ->and($connection->stringSetting('idp_sso_url'))->toBe('https://idp.test/saml/sso')
        ->and($connection->sp_certificate)->toContain('BEGIN CERTIFICATE')
        ->and($connection->toArray())->not->toHaveKey('sp_private_key');
});

test('people who can’t manage settings can’t see or change SSO', function () {
    $workspace = SsoFixtures::workspace();
    $member = SsoFixtures::member($workspace, 'ada@acme.test');

    $this->actingAs($member)->get($workspace->url('/settings/sso'))->assertForbidden();
});

test('changing the issuer unlinks people, so a new provider can’t take over accounts', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $connection->identities()->create(['subject' => 's1', 'user_id' => $workspace->owner_id]);
    Http::fake(['https://other-idp.test/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://other-idp.test', 'authorization_endpoint' => 'https://other-idp.test/a', 'token_endpoint' => 'https://other-idp.test/t', 'jwks_uri' => 'https://other-idp.test/j',
    ])]);

    $this->actingAs($workspace->owner)->put($workspace->url('/settings/sso/connections/'.$connection->uuid), [
        'name' => 'Acme IdP', 'default_role' => 'member',
        'settings' => ['issuer' => 'https://other-idp.test', 'client_id' => FakeIdp::CLIENT_ID],
    ])->assertSessionHasNoErrors();

    expect($connection->identities()->count())->toBe(0)
        ->and(Activity::query()->where('action', 'sso.identities_reset')->exists())->toBeTrue();
});
