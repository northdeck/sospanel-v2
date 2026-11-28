<?php

use App\Models\User;
use App\Northdeck\Updates\SourceUpdate;
use App\Northdeck\Updates\SourceUpdates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

const OLD_COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const NEW_COMMIT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function () {
    $this->withoutVite();
    config(['northdeck.admin.enabled' => true, 'northdeck.updates.repo' => 'northdeck/sospanel-v2', 'northdeck.updates.branch' => 'main', 'northdeck.updates.auto' => false]);
    $running = OLD_COMMIT;
    SourceUpdates::$currentResolver = function () use (&$running) {
        return $running;
    };
    $this->running = &$running;
    Http::fake([
        'api.github.com/repos/northdeck/sospanel-v2/commits/main' => Http::response([
            'sha' => NEW_COMMIT, 'html_url' => 'https://github.com/northdeck/sospanel-v2/commit/'.NEW_COMMIT,
            'commit' => ['message' => "Add a thing\n\nMore words", 'author' => ['name' => 'Braiden', 'date' => '2026-11-28T10:00:00Z']],
        ]),
        'api.github.com/repos/northdeck/sospanel-v2/compare/*' => Http::response([
            'ahead_by' => 2,
            'commits' => [
                ['sha' => str_repeat('c', 40), 'commit' => ['message' => 'First'], 'html_url' => null],
                ['sha' => NEW_COMMIT, 'commit' => ['message' => 'Add a thing'], 'html_url' => null],
            ],
        ]),
    ]);
});

afterEach(function () {
    SourceUpdates::$currentResolver = null;
});

function updatesAdmin(): User
{
    return User::factory()->create(['is_admin' => true, 'email_verified_at' => now(), 'two_factor_confirmed_at' => now()]);
}

function updaterCheckedIn(): void
{
    Cache::forever(SourceUpdates::UPDATER_SEEN, now()->getTimestamp());
}

test('the check finds a newer commit on the branch and what changed', function () {
    $check = SourceUpdates::check();

    expect($check['reached'])->toBeTrue()
        ->and($check['latest']['short'])->toBe('bbbbbbb')
        ->and($check['latest']['message'])->toBe('Add a thing')
        ->and($check['behind'])->toBe(2)
        ->and($check['commits'][0]['sha'])->toBe(NEW_COMMIT)
        ->and(SourceUpdates::available())->toBe(NEW_COMMIT);
});

test('nothing to do when the panel is on the newest commit', function () {
    $this->running = NEW_COMMIT;

    SourceUpdates::check();
    expect(SourceUpdates::available())->toBeNull();
});

test('a GitHub error is kept, not thrown', function () {
    config(['northdeck.updates.branch' => 'gone']);
    Http::fake(['api.github.com/repos/northdeck/sospanel-v2/commits/gone' => Http::response([], 404)]);

    $check = SourceUpdates::check();
    expect($check['reached'])->toBeFalse()
        ->and($check['error'])->toContain('no gone branch');
});

test('Update now needs the server updater to have checked in', function () {
    SourceUpdates::check();
    $admin = updatesAdmin();

    $this->actingAs($admin)->post('/admin/updates')->assertSessionHasErrors('update');
    expect(SourceUpdate::query()->count())->toBe(0);

    updaterCheckedIn();
    $this->actingAs($admin)->post('/admin/updates')->assertSessionHasNoErrors();
    expect(SourceUpdate::query()->first())
        ->commit->toBe(NEW_COMMIT)
        ->from_commit->toBe(OLD_COMMIT)
        ->status->toBe(SourceUpdate::REQUESTED);

    // Only one at a time.
    $this->actingAs($admin)->post('/admin/updates')->assertSessionHasErrors('update');
});

test('the server updater claims it, and it’s done once the panel runs the commit', function () {
    SourceUpdates::check();
    updaterCheckedIn();
    $update = SourceUpdates::request(null);

    $this->artisan('northdeck:update-claim')->expectsOutput("{$update->id} ".NEW_COMMIT.' northdeck/sospanel-v2')->assertSuccessful();
    expect($update->refresh()->status)->toBe(SourceUpdate::RUNNING);
    $this->artisan('northdeck:update-claim')->doesntExpectOutputToContain(NEW_COMMIT)->assertSuccessful();

    $this->running = NEW_COMMIT;
    $this->artisan('northdeck:update-finished', ['id' => $update->id])->assertSuccessful();
    expect($update->refresh()->status)->toBe(SourceUpdate::DONE);
});

test('an update that comes back on the old commit, or reports failure, failed', function () {
    SourceUpdates::check();
    updaterCheckedIn();
    $update = SourceUpdates::request(null);
    SourceUpdates::claim();

    $this->artisan('northdeck:update-finished', ['id' => $update->id])->assertSuccessful();
    expect($update->refresh())->status->toBe(SourceUpdate::FAILED)
        ->error->toContain('aaaaaaa');

    $second = SourceUpdate::query()->create(['commit' => NEW_COMMIT, 'status' => SourceUpdate::RUNNING, 'started_at' => now()]);
    $this->artisan('northdeck:update-finished', ['id' => $second->id, '--failed' => 'Build broke'])->assertSuccessful();
    expect($second->refresh())->status->toBe(SourceUpdate::FAILED)->error->toBe('Build broke');
});

test('auto-update asks for the new commit at its hour, once', function () {
    config(['northdeck.updates.auto' => true, 'northdeck.updates.auto_hour' => now()->hour]);
    updaterCheckedIn();

    $this->artisan('northdeck:check-updates')->assertSuccessful();
    expect(SourceUpdate::query()->where('auto', true)->where('commit', NEW_COMMIT)->count())->toBe(1);

    // A commit that failed by itself isn't tried again by itself.
    SourceUpdate::query()->update(['status' => SourceUpdate::FAILED]);
    $this->artisan('northdeck:check-updates')->assertSuccessful();
    expect(SourceUpdate::query()->count())->toBe(1);
});

test('auto-update waits for its hour, and does nothing when off', function () {
    config(['northdeck.updates.auto' => true, 'northdeck.updates.auto_hour' => (now()->hour + 1) % 24]);
    updaterCheckedIn();
    $this->artisan('northdeck:check-updates')->assertSuccessful();

    config(['northdeck.updates.auto' => false, 'northdeck.updates.auto_hour' => null]);
    $this->artisan('northdeck:check-updates')->assertSuccessful();

    expect(SourceUpdate::query()->count())->toBe(0);
});

test('requests nobody picks up expire', function () {
    $stale = SourceUpdate::query()->create(['commit' => NEW_COMMIT, 'status' => SourceUpdate::REQUESTED]);
    $stale->forceFill(['created_at' => now()->subHour()])->save();

    SourceUpdates::expireStale();
    expect($stale->refresh()->status)->toBe(SourceUpdate::FAILED);
});

test('the updates page shows where it stands, for admins only', function () {
    SourceUpdates::check();
    $this->actingAs(User::factory()->create())->get('/admin/updates')->assertForbidden();

    $this->actingAs(updatesAdmin())->get('/admin/updates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/NorthdeckUpdates')
            ->where('current', OLD_COMMIT)
            ->where('available', true)
            ->where('check.behind', 2)
            ->where('updater', false));
});

test('admins send a test email with the settings in force', function () {
    Mail::fake();
    config(['mail.default' => 'array']);

    $this->actingAs(updatesAdmin())->post('/admin/settings/test-email', ['to' => 'me@acme.test'])->assertSessionHasNoErrors();
    $this->actingAs(updatesAdmin())->post('/admin/settings/test-email', ['to' => 'nope'])->assertSessionHasErrors('to');
    $this->actingAs(User::factory()->create())->post('/admin/settings/test-email', ['to' => 'me@acme.test'])->assertForbidden();
});

test('a test email that can’t send says why', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);

    $this->actingAs(updatesAdmin())->post('/admin/settings/test-email', ['to' => 'me@acme.test'])
        ->assertSessionHasErrors(['to' => 'Couldn’t reach a mail server at 127.0.0.1:1. Check the host and port, and that this server may connect out on that port (some hosts block 25 and 587).']);
});
