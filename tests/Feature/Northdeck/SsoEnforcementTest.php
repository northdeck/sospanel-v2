<?php

use App\Models\Activity;
use App\Northdeck\Sso\SsoSignIn;
use Tests\Support\Northdeck\SsoFixtures;

beforeEach(function () {
    $this->withoutVite();
    config()->set('northdeck.sso.enabled', true);
    config()->set('northdeck.sso.allow_private_hosts', true);
});

function requiringSso(): array
{
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $workspace->forceFill(['require_sso' => true])->save();

    return [$workspace, $connection];
}

test('a member can’t sign in with a password once SSO is required', function () {
    [$workspace] = requiringSso();
    $member = SsoFixtures::member($workspace, 'ada@acme.test');

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => "{$workspace->name} asks you to sign in with SSO."]);
    $this->assertGuest();
});

test('a wrong password says nothing about SSO', function () {
    [$workspace] = requiringSso();
    $member = SsoFixtures::member($workspace, 'ada@acme.test');

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => 'That email and password don’t match an account.']);
    $this->assertGuest();
});

test('a member signed in some other way is sent to SSO inside the workspace', function () {
    [$workspace] = requiringSso();
    $member = SsoFixtures::member($workspace, 'ada@acme.test');

    $this->actingAs($member)->get($workspace->url())
        ->assertInertia(fn ($page) => $page->component('auth/NorthdeckSsoRequired'));
});

test('a member who signed in with the workspace’s connection gets in', function () {
    [$workspace, $connection] = requiringSso();
    $member = SsoFixtures::member($workspace, 'ada@acme.test');

    $this->actingAs($member)
        ->withSession([SsoSignIn::SESSION => ['user_id' => $member->id, 'connection' => $connection->uuid, 'workspace_id' => $workspace->id]])
        ->get($workspace->url())
        ->assertOk()
        ->assertInertia(fn ($page) => expect($page->toArray()['component'])->not->toBe('auth/NorthdeckSsoRequired'));
});

test('break-glass: the owner with two-factor on can still use a password, and it is audited', function () {
    [$workspace] = requiringSso();
    $owner = $workspace->owner;
    $owner->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($owner)->get($workspace->url())->assertOk();
    $this->actingAs($owner)->get($workspace->url())->assertOk();

    expect(Activity::query()->where('action', 'sso.break_glass')->count())->toBe(1);
});

test('the owner without two-factor has no break-glass', function () {
    [$workspace] = requiringSso();

    $this->actingAs($workspace->owner)->get($workspace->url())
        ->assertInertia(fn ($page) => $page->component('auth/NorthdeckSsoRequired'));
});

test('an instance admin with two-factor on can break glass', function () {
    [$workspace] = requiringSso();
    $admin = SsoFixtures::member($workspace, 'root@acme.test');
    $admin->forceFill(['is_admin' => true, 'two_factor_confirmed_at' => now()])->save();

    $this->actingAs($admin)->get($workspace->url())->assertOk();
    expect(Activity::query()->where('action', 'sso.break_glass')->where('meta->admin', true)->exists())->toBeTrue();
});

test('Require SSO can’t be turned on without a connection on a verified domain', function () {
    $workspace = SsoFixtures::workspace();
    $owner = $workspace->owner;

    $this->actingAs($owner)->put($workspace->url('/settings/sso/require'), ['require' => true])
        ->assertSessionHasErrors('require');
    expect($workspace->fresh()->require_sso)->toBeFalse();
});

test('turning Require SSO on and off is audited', function () {
    $workspace = SsoFixtures::workspace();
    SsoFixtures::oidc($workspace);
    $owner = $workspace->owner;
    $owner->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($owner)->put($workspace->url('/settings/sso/require'), ['require' => true])->assertSessionHasNoErrors();
    $this->actingAs($owner)->put($workspace->url('/settings/sso/require'), ['require' => false])->assertSessionHasNoErrors();

    expect(Activity::query()->whereIn('action', ['sso.require_on', 'sso.require_off'])->pluck('action')->all())
        ->toBe(['sso.require_on', 'sso.require_off']);
});
