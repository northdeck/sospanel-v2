<?php

use App\Models\AdminAction;
use App\Models\User;
use App\Northdeck\Admin\PanelSettings;
use App\Notifications\EmailChanged;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutVite();
    config()->set('northdeck.admin.enabled', true);
    PanelSettings::reset();
});

afterEach(function () {
    PanelSettings::reset();
});

function instanceAdmin(): User
{
    return User::factory()->create([
        'is_admin' => true,
        'email_verified_at' => now(),
        'two_factor_confirmed_at' => now(),
    ]);
}

test('only instance admins open the admin pages', function () {
    $this->actingAs(User::factory()->create())->get('/admin/panel')->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();

    $this->actingAs(instanceAdmin())->get('/admin/panel')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/NorthdeckPanel'));
});

test('the admin pages are gone when NORTHDECK_ADMIN is off', function () {
    config()->set('northdeck.admin.enabled', false);

    $this->actingAs(instanceAdmin())->get('/admin/panel')->assertNotFound();
    $this->actingAs(instanceAdmin())->get('/admin/users')->assertNotFound();
});

test('a saved panel name and addresses go over .env, and clearing goes back', function () {
    config()->set('app.name', 'From env');
    $admin = instanceAdmin();

    $this->actingAs($admin)->put('/admin/panel', [
        'name' => 'Acme Panel',
        'support_email' => 'help@acme.test',
        'security_email' => 'security@acme.test',
        'mail_from_address' => 'panel@acme.test',
    ])->assertSessionHasNoErrors();

    expect(config('app.name'))->toBe('Acme Panel')
        ->and(config('mail.support.address'))->toBe('help@acme.test')
        ->and(config('northdeck.support.security_email'))->toBe('security@acme.test')
        ->and(config('mail.from.address'))->toBe('panel@acme.test')
        ->and(AdminAction::query()->where('action', 'northdeck.panel_settings')->value('meta'))
        ->toBe(['changed' => ['name', 'support_email', 'security_email', 'mail_from_address']]);

    // A fresh boot reads them back from the database.
    config()->set('app.name', 'From env');
    PanelSettings::reset();
    PanelSettings::apply(config());
    expect(config('app.name'))->toBe('Acme Panel');

    $this->actingAs($admin)->get('/admin/panel')
        ->assertInertia(fn ($page) => $page->where('settings.name.value', 'Acme Panel')
            ->where('settings.name.env', 'From env')
            ->where('settings.name.saved', true));

    $this->actingAs($admin)->put('/admin/panel', ['name' => ''])->assertSessionHasNoErrors();
    expect(config('app.name'))->toBe('From env')
        ->and(PanelSettings::saved())->not->toHaveKey('name');
});

test('bad addresses are refused', function () {
    $this->actingAs(instanceAdmin())->put('/admin/panel', [
        'support_email' => 'not an email',
        'support_url' => 'javascript:alert(1)',
    ])->assertSessionHasErrors(['support_email', 'support_url']);

    expect(PanelSettings::saved())->toBe([]);
});

test('the users page lists and searches people', function () {
    $admin = instanceAdmin();
    User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@acme.test']);
    User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@acme.test']);

    $this->actingAs($admin)->get('/admin/users?q=grace')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/NorthdeckUsers')
            ->where('users.total', 1)
            ->where('users.data.0.email', 'grace@acme.test'));
});

test('an admin changes someone’s name and email; the old address hears about it', function () {
    Notification::fake();
    $admin = instanceAdmin();
    $user = User::factory()->create(['name' => 'Grace', 'email' => 'grace@acme.test', 'email_verified_at' => now()]);

    $this->actingAs($admin)->put("/admin/users/{$user->id}", [
        'name' => 'Grace Hopper',
        'email' => 'Grace.Hopper@acme.test',
    ])->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('Grace Hopper')
        ->and($user->email)->toBe('grace.hopper@acme.test')
        ->and($user->email_verified_at)->toBeNull()
        ->and(AdminAction::query()->where('action', 'user.updated')->where('target_id', $user->id)->exists())->toBeTrue();

    Notification::assertSentOnDemand(EmailChanged::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'grace@acme.test');
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('an admin can mark the new address as verified', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'grace@acme.test']);

    $this->actingAs(instanceAdmin())->put("/admin/users/{$user->id}", [
        'name' => $user->name, 'email' => 'grace.hopper@acme.test', 'verified' => true,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()?->email_verified_at)->not->toBeNull();
    Notification::assertNotSentTo($user, VerifyEmail::class);
});

test('an email someone else uses is refused', function () {
    User::factory()->create(['email' => 'taken@acme.test']);
    $user = User::factory()->create(['email' => 'grace@acme.test']);

    $this->actingAs(instanceAdmin())->put("/admin/users/{$user->id}", [
        'name' => $user->name, 'email' => 'taken@acme.test',
    ])->assertSessionHasErrors('email');
});

test('admins can make others admins, but can’t take it from themselves', function () {
    $admin = instanceAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)->put("/admin/users/{$user->id}", ['name' => $user->name, 'email' => $user->email, 'admin' => true])
        ->assertSessionHasNoErrors();
    expect($user->fresh()?->is_admin)->toBeTrue();

    $this->actingAs($admin)->put("/admin/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'admin' => false])
        ->assertSessionHasErrors('admin');
    expect($admin->fresh()?->is_admin)->toBeTrue();
});

test('the admin sidebar lists the Northdeck pages', function () {
    $this->actingAs(instanceAdmin())->get('/admin/users')
        ->assertInertia(fn ($page) => $page->where('adminNav', fn ($groups) => collect($groups)
            ->flatMap(fn ($group) => $group['items'])->pluck('href')->intersect(['/admin/users', '/admin/panel'])->count() === 2));
});
