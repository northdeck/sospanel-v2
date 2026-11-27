<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Northdeck\Sso\Claims;
use App\Northdeck\Sso\Directory;
use App\Northdeck\Sso\Models\SsoGroupMapping;
use App\Northdeck\Sso\Models\SsoIdentity;
use App\Northdeck\Sso\SsoSignIn;
use Tests\Support\Northdeck\FakeIdp;
use Tests\Support\Northdeck\SsoFixtures;

function claims(string $subject, string $email, bool $verified = true, array $groups = []): Claims
{
    return new Claims($subject, $email, $verified, 'Someone', $groups, []);
}

beforeEach(function () {
    $this->withoutVite();
    config()->set('northdeck.sso.enabled', true);
    config()->set('northdeck.sso.allow_private_hosts', true);
    FakeIdp::oidc();
});

test('typing an email sends you to the connection for its verified domain', function () {
    $workspace = SsoFixtures::workspace('acme.test');
    $connection = SsoFixtures::oidc($workspace);

    $response = $this->post(route('northdeck.sso.lookup'), ['email' => 'Ada@Acme.test']);

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->toStartWith(route('northdeck.sso.start', $connection))
        ->toContain('login_hint=ada%40acme.test');
});

test('an unverified domain sends nobody anywhere', function () {
    $workspace = SsoFixtures::workspace('acme.test', verified: false);
    SsoFixtures::oidc($workspace);

    expect(Directory::forEmail('ada@acme.test'))->toBeNull();
    $this->post(route('northdeck.sso.lookup'), ['email' => 'ada@acme.test'])->assertSessionHasErrors('email');
});

test('/sso/{workspace} goes straight to the workspace’s only connection', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);

    $this->get('/sso/'.$workspace->slug)->assertRedirect(route('northdeck.sso.start', $connection));
});

test('an existing account is linked when the provider verifies an email on a verified domain', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $existing = SsoFixtures::member($workspace, 'ada@acme.test');

    expect(app(SsoSignIn::class)->plan($connection, claims('sub-1', 'ada@acme.test')))
        ->toMatchArray(['action' => 'link', 'user_id' => $existing->id]);
});

test('an unverified email claim never links to an existing account', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    SsoFixtures::member($workspace, 'ada@acme.test');

    $plan = app(SsoSignIn::class)->plan($connection, claims('sub-1', 'ada@acme.test', verified: false));

    expect($plan['action'])->toBe('refuse')->and($plan['reason'])->toContain('didn’t confirm');
});

test('an email outside the connection’s verified domains is refused', function () {
    $workspace = SsoFixtures::workspace('acme.test');
    $connection = SsoFixtures::oidc($workspace);
    User::factory()->create(['email' => 'victim@bank.test']);

    expect(app(SsoSignIn::class)->plan($connection, claims('sub-1', 'victim@bank.test'))['action'])->toBe('refuse');
});

test('identities are keyed on the subject, so a changed email still signs in the same person', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $user = SsoFixtures::member($workspace, 'ada@acme.test');
    SsoIdentity::query()->create(['connection_id' => $connection->id, 'subject' => 'sub-1', 'user_id' => $user->id]);
    $other = User::factory()->create(['email' => 'grace@acme.test']);

    $plan = app(SsoSignIn::class)->plan($connection, claims('sub-1', 'grace@acme.test'));

    expect($plan)->toMatchArray(['action' => 'sign_in', 'user_id' => $user->id])->and($plan['user_id'])->not->toBe($other->id);
});

test('with JIT off, people without an account are refused', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace, ['jit' => false]);

    expect(app(SsoSignIn::class)->plan($connection, claims('sub-9', 'new@acme.test'))['action'])->toBe('refuse');
});

test('groups map to roles at every sign-in, the highest wins, and the owner keeps theirs', function () {
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    SsoGroupMapping::query()->create(['connection_id' => $connection->id, 'group' => 'viewers', 'role' => 'viewer']);
    SsoGroupMapping::query()->create(['connection_id' => $connection->id, 'group' => 'platform', 'role' => 'admin']);
    $connection->load('groupMappings');
    $signIn = app(SsoSignIn::class);
    $user = SsoFixtures::member($workspace, 'ada@acme.test');

    $signIn->syncMembership($workspace, $connection, $user, ['viewers', 'platform']);
    expect($workspace->roleOf($user))->toBe(WorkspaceRole::Admin);

    $signIn->syncMembership($workspace, $connection, $user, ['viewers']);
    expect($workspace->roleOf($user))->toBe(WorkspaceRole::Viewer);

    $signIn->syncMembership($workspace, $connection, $workspace->owner, ['viewers']);
    expect($workspace->roleOf($workspace->owner))->toBe(WorkspaceRole::Owner);
});
