<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Northdeck\Admin\PanelInvite;
use App\Northdeck\Admin\PanelInviteSent;
use App\Northdeck\Admin\PanelSettings;
use App\Northdeck\NorthdeckServiceProvider;
use App\Northdeck\Sso\Claims;
use App\Northdeck\Sso\SsoSignIn;
use App\Notifications\WorkspaceInvitationSent;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\SelfHostedEntitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Northdeck\FakeIdp;
use Tests\Support\Northdeck\SsoFixtures;

beforeEach(function () {
    $this->withoutVite();
    config(['serveros.edition' => 'self-hosted', 'northdeck.admin.enabled' => true]);
    app()->forgetInstance(Entitlements::class);
    app()->singleton(Entitlements::class, fn () => new SelfHostedEntitlements);
    DB::table('install_identity')->where('id', 1)->update(['set_up_at' => now()]);
    PanelSettings::reset();
    // Someone already has an account, so the first-account rule doesn't apply.
    User::factory()->create(['email' => 'owner@northdeck.test']);
});

afterEach(fn () => PanelSettings::reset());

function signUpRules(string $mode, array $domains = [], array $blocked = []): void
{
    config(['northdeck.signups.mode' => $mode, 'northdeck.signups.domains' => $domains, 'northdeck.signups.blocked_domains' => $blocked]);
    NorthdeckServiceProvider::applyDefaults(config());
}

function signUp(object $test, string $email): object
{
    return $test->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
}

function adminInvite(string $email): PanelInvite
{
    return PanelInvite::query()->create(['email' => $email, 'expires_at' => now()->addDays(14)]);
}

test('open lets anyone sign up', function () {
    signUpRules('open');

    signUp($this, 'ada@anywhere.test')->assertSessionHasNoErrors();
    expect(User::query()->where('email', 'ada@anywhere.test')->exists())->toBeTrue();
});

test('domains lets in only the allowed domains and their subdomains', function () {
    signUpRules('domains', ['acme.com']);

    signUp($this, 'ada@gmail.com')->assertSessionHasErrors(['email' => 'New accounts on this panel are for @acme.com emails. Ask its admin for an invite.']);
    signUp($this, 'ada@eu.acme.com')->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'ada@gmail.com')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'ada@eu.acme.com')->exists())->toBeTrue();
});

test('blocked domains never sign up, even with sign-up open', function () {
    signUpRules('open', blocked: ['spam.test']);

    signUp($this, 'bot@spam.test')->assertSessionHasErrors(['email' => 'Accounts on spam.test can’t be made on this panel. Ask its admin for an invite.']);
    expect(User::query()->where('email', 'bot@spam.test')->exists())->toBeFalse();
});

test('off refuses everyone, and the sign-in page drops "Create an account"', function () {
    signUpRules('off');

    signUp($this, 'ada@acme.com')->assertSessionHasErrors(['email' => 'Sign-up is off on this panel. Ask its admin for an invite.']);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('northdeckSignUpOff', true));
    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('northdeckSignUps.mode', 'off'));
});

test('an admin invite gets in whatever the rules, on any domain, and is used up', function (string $mode) {
    signUpRules($mode, ['acme.com'], ['outside.test']);
    adminInvite('Contractor@Outside.test');

    signUp($this, 'contractor@outside.test')->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'contractor@outside.test')->exists())->toBeTrue()
        ->and(PanelInvite::query()->first()?->accepted_at)->not->toBeNull()
        ->and(PanelInvite::pendingFor('contractor@outside.test'))->toBeFalse();
})->with(['off', 'domains', 'invite']);

test('an expired admin invite doesn’t count', function () {
    signUpRules('off');
    PanelInvite::query()->create(['email' => 'late@acme.com', 'expires_at' => now()->subDay()]);

    signUp($this, 'late@acme.com')->assertSessionHasErrors('email');
});

test('invite mode is upstream’s: a workspace invitation still works', function () {
    signUpRules('invite');

    signUp($this, 'ada@acme.com')->assertSessionHasErrors('email');
    expect(config('serveros.sign_ups'))->toBe('closed');
});

test('the invite link fills in the email', function () {
    $this->get(route('register', ['email' => 'ada@acme.com']))
        ->assertInertia(fn (Assert $page) => $page->where('email', 'ada@acme.com'));
});

function signUpAdmin(): User
{
    return User::factory()->create(['is_admin' => true, 'email_verified_at' => now(), 'two_factor_confirmed_at' => now()]);
}

test('admins invite someone; they get the sign-up email', function () {
    Notification::fake();

    $this->actingAs(signUpAdmin())->post('/admin/invites', ['email' => 'Grace@Outside.test'])->assertSessionHasNoErrors();

    $invite = PanelInvite::query()->firstOrFail();
    expect($invite->email)->toBe('grace@outside.test')
        ->and($invite->expires_at->isFuture())->toBeTrue();
    Notification::assertSentOnDemand(PanelInviteSent::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'grace@outside.test'
        && str_contains($n->url(), 'email=grace%40outside.test'));
});

test('an invite with a workspace sends that workspace’s invitation too', function () {
    Notification::fake();
    $workspace = SsoFixtures::workspace();

    $this->actingAs(signUpAdmin())->post('/admin/invites', ['email' => 'grace@outside.test', 'workspace_id' => $workspace->id, 'role' => 'viewer'])
        ->assertSessionHasNoErrors();

    expect($workspace->invitations()->where('email', 'grace@outside.test')->value('role'))->toBe(WorkspaceRole::Viewer);
    Notification::assertSentOnDemand(WorkspaceInvitationSent::class);
    Notification::assertSentOnDemandTimes(PanelInviteSent::class, 0);
});

test('someone who already has an account isn’t invited', function () {
    $this->actingAs(signUpAdmin())->post('/admin/invites', ['email' => 'owner@northdeck.test'])->assertSessionHasErrors('email');
});

test('only admins invite, and invites can be sent again or cancelled', function () {
    Notification::fake();
    $this->actingAs(User::factory()->create())->post('/admin/invites', ['email' => 'x@y.test'])->assertForbidden();

    $admin = signUpAdmin();
    $invite = adminInvite('grace@outside.test');
    $this->actingAs($admin)->post("/admin/invites/{$invite->id}/resend")->assertSessionHasNoErrors();
    Notification::assertSentOnDemand(PanelInviteSent::class);

    $this->actingAs($admin)->delete("/admin/invites/{$invite->id}")->assertSessionHasNoErrors();
    expect(PanelInvite::query()->count())->toBe(0);
});

test('with SSO account-making off, SSO only signs in people who already have one', function () {
    config(['northdeck.sso.enabled' => true, 'northdeck.sso.allow_private_hosts' => true, 'northdeck.signups.sso' => false]);
    FakeIdp::oidc();
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $claims = new Claims('s-new', 'new@acme.test', true, 'New Person', [], []);

    expect(app(SsoSignIn::class)->plan($connection, $claims)['action'])->toBe('refuse');

    adminInvite('new@acme.test');
    expect(app(SsoSignIn::class)->plan($connection, $claims)['action'])->toBe('create');
});

test('SSO follows the blocked domains', function () {
    config(['northdeck.sso.enabled' => true, 'northdeck.sso.allow_private_hosts' => true]);
    signUpRules('open', blocked: ['acme.test']);
    FakeIdp::oidc();
    $workspace = SsoFixtures::workspace();
    $connection = SsoFixtures::oidc($workspace);
    $claims = new Claims('s-new', 'new@acme.test', true, 'New Person', [], []);

    expect(app(SsoSignIn::class)->plan($connection, $claims)['action'])->toBe('refuse');
});

test('sign-up rules saved on the settings page apply right away', function () {
    $this->actingAs(signUpAdmin())->put('/admin/settings', ['values' => ['signups_mode' => 'off']])->assertSessionHasNoErrors();
    auth()->logout();

    signUp($this, 'ada@acme.com')->assertSessionHasErrors('email');
});
