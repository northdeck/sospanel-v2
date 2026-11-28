<?php

namespace App\Northdeck\Updates;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Updates from Northdeck's own repository. Upstream's updater follows
 * ServerOS releases and pulls a published image; Northdeck's build has
 * neither, so:
 *
 *  - the image records the commit it was built from (NORTHDECK_COMMIT);
 *  - the panel asks GitHub for the newest commit on the branch, hourly;
 *  - an update (asked for on /admin/updates, or by auto-update) waits here
 *    until the server's updater (serveros-panel updater, every minute) claims
 *    it, builds that commit and restarts the panel on it.
 */
final class SourceUpdates
{
    public const string LATEST = 'northdeck.updates.latest';

    public const string UPDATER_SEEN = 'northdeck.updates.updater-seen';

    private const int STALE_MINUTES = 45;

    /** @var (\Closure(): ?string)|null swaps the running commit out in tests */
    public static ?\Closure $currentResolver = null;

    /** The commit this panel was built from, or null if nobody knows. */
    public static function current(): ?string
    {
        if (self::$currentResolver !== null) {
            return (self::$currentResolver)();
        }

        $file = base_path('NORTHDECK_COMMIT');
        if (is_file($file)) {
            $commit = trim((string) file_get_contents($file));

            return self::isCommit($commit) ? $commit : null;
        }

        if (is_dir(base_path('.git'))) {
            $result = rescue(fn () => Process::path(base_path())->timeout(5)->run(['git', 'rev-parse', 'HEAD']), null, false);
            $commit = $result?->successful() ? trim($result->output()) : '';

            return self::isCommit($commit) ? $commit : null;
        }

        return null;
    }

    public static function isCommit(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}$/', $value) === 1;
    }

    public static function repo(): string
    {
        $repo = (string) config('northdeck.updates.repo', 'northdeck/sospanel-v2');

        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) === 1 ? $repo : 'northdeck/sospanel-v2';
    }

    public static function branch(): string
    {
        $branch = (string) config('northdeck.updates.branch', 'main');

        return preg_match('#^[A-Za-z0-9_./-]+$#', $branch) === 1 ? $branch : 'main';
    }

    /**
     * Asks GitHub for the newest commit, and how far behind this panel is.
     *
     * @return array<string, mixed>
     */
    public static function check(): array
    {
        $check = ['checkedAt' => now()->getTimestamp(), 'reached' => false, 'latest' => null, 'behind' => null, 'commits' => [], 'error' => null];

        try {
            $head = self::github()->get('repos/'.self::repo().'/commits/'.rawurlencode(self::branch()));
            if (! $head->successful()) {
                throw new RuntimeException($head->status() === 404
                    ? 'GitHub has no '.self::branch().' branch on '.self::repo().' (or it’s private: add a token).'
                    : 'GitHub answered '.$head->status().'.');
            }
            $sha = (string) $head->json('sha');
            if (! self::isCommit($sha)) {
                throw new RuntimeException('GitHub didn’t send a commit.');
            }
            $check['reached'] = true;
            $check['latest'] = self::commit($head->json());

            $current = self::current();
            if ($current !== null && $current !== $sha) {
                $compare = self::github()->get('repos/'.self::repo()."/compare/{$current}...{$sha}");
                if ($compare->successful()) {
                    $check['behind'] = (int) $compare->json('ahead_by');
                    $commits = (array) $compare->json('commits', []);
                    $check['commits'] = array_values(array_map(fn ($c) => self::commit(is_array($c) ? $c : []), array_slice(array_reverse($commits), 0, 20)));
                }
            } elseif ($current === $sha) {
                $check['behind'] = 0;
            }
        } catch (Throwable $e) {
            $check['error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Couldn’t reach GitHub.';
            $last = self::lastCheck();
            $check['latest'] = $last['latest'] ?? null;
        }

        Cache::forever(self::LATEST, $check);

        return $check;
    }

    /** @return array<string, mixed>|null */
    public static function lastCheck(): ?array
    {
        $check = Cache::get(self::LATEST);

        return is_array($check) ? $check : null;
    }

    /** The newest commit, if this panel isn't on it. */
    public static function available(): ?string
    {
        $latest = self::lastCheck()['latest']['sha'] ?? null;
        if (! is_string($latest) || ! self::isCommit($latest)) {
            return null;
        }
        $behind = self::lastCheck()['behind'] ?? null;

        return $latest !== self::current() && $behind !== 0 ? $latest : null;
    }

    public static function inFlight(): ?SourceUpdate
    {
        return SourceUpdate::query()->whereIn('status', [SourceUpdate::REQUESTED, SourceUpdate::RUNNING])->latest('id')->first();
    }

    public static function updaterSeen(): bool
    {
        $seen = Cache::get(self::UPDATER_SEEN);

        return is_numeric($seen) && (int) $seen >= now()->subMinutes(5)->getTimestamp();
    }

    /** @throws RuntimeException */
    public static function request(?User $user, bool $auto = false): SourceUpdate
    {
        self::expireStale();
        if (self::inFlight() !== null) {
            throw new RuntimeException('An update is already on its way.');
        }
        if (! self::updaterSeen()) {
            throw new RuntimeException('The server isn’t running Northdeck’s updater, so the panel can’t update itself yet. See docs/admin.md, "Updates".');
        }
        $commit = self::available() ?? throw new RuntimeException('This panel already runs the newest commit.');

        return SourceUpdate::query()->create([
            'commit' => $commit,
            'from_commit' => self::current(),
            'auto' => $auto,
            'requested_by' => $user?->id,
        ]);
    }

    /** Asks for an update if auto-update is on and it's time. */
    public static function autoUpdate(): ?SourceUpdate
    {
        if (! (bool) config('northdeck.updates.auto') || self::available() === null || self::inFlight() !== null || ! self::updaterSeen()) {
            return null;
        }
        $hour = config('northdeck.updates.auto_hour');
        if (is_numeric($hour) && (int) $hour !== now()->hour) {
            return null;
        }
        // Don't retry a commit that already failed to install by itself.
        $commit = self::available();
        if (SourceUpdate::query()->where('commit', $commit)->where('status', SourceUpdate::FAILED)->where('auto', true)->exists()) {
            return null;
        }

        return rescue(fn () => self::request(null, auto: true), null, false);
    }

    /** For the server's updater: the next update to carry out. */
    public static function claim(): ?SourceUpdate
    {
        Cache::forever(self::UPDATER_SEEN, now()->getTimestamp());
        self::expireStale();

        $update = SourceUpdate::query()->where('status', SourceUpdate::REQUESTED)->oldest('id')->first();
        if ($update === null) {
            return null;
        }
        $claimed = SourceUpdate::query()->whereKey($update->id)->where('status', SourceUpdate::REQUESTED)
            ->update(['status' => SourceUpdate::RUNNING, 'started_at' => now(), 'updated_at' => now()]);

        return $claimed === 1 ? $update->refresh() : null;
    }

    /** The updater reports back, from the restarted panel. */
    public static function finish(SourceUpdate $update, ?string $failed = null): void
    {
        if ($failed !== null) {
            $update->fail($failed);

            return;
        }

        $running = self::current();
        if ($running !== $update->commit) {
            $update->fail('The panel came back on '.($running === null ? 'an unknown commit' : substr($running, 0, 7)).', not '.substr($update->commit, 0, 7).'.');

            return;
        }

        $update->forceFill(['status' => SourceUpdate::DONE, 'error' => null, 'finished_at' => now()])->save();
        Cache::forget(self::LATEST);
        rescue(fn () => self::check(), report: false);
    }

    public static function expireStale(): void
    {
        $stale = now()->subMinutes(self::STALE_MINUTES);
        SourceUpdate::query()->where('status', SourceUpdate::REQUESTED)->where('created_at', '<', $stale)->get()
            ->each(fn (SourceUpdate $u) => $u->fail('The server didn’t pick the update up. Check the updater runs there: serveros-panel schedule.'));
        SourceUpdate::query()->where('status', SourceUpdate::RUNNING)->where('started_at', '<', $stale)->get()
            ->each(fn (SourceUpdate $u) => $u->fail('The update didn’t finish. See /var/log/serveros-panel-update.log on the server.'));
    }

    /**
     * @param  array<mixed>  $data  a GitHub commit
     * @return array{sha: string, short: string, message: string, author: string|null, date: string|null, url: string|null}
     */
    private static function commit(array $data): array
    {
        $sha = is_string($data['sha'] ?? null) ? $data['sha'] : '';
        $message = is_string($data['commit']['message'] ?? null) ? $data['commit']['message'] : '';

        return [
            'sha' => $sha,
            'short' => substr($sha, 0, 7),
            'message' => mb_substr((string) strtok($message, "\n"), 0, 200),
            'author' => is_string($data['commit']['author']['name'] ?? null) ? $data['commit']['author']['name'] : null,
            'date' => is_string($data['commit']['author']['date'] ?? null) ? $data['commit']['author']['date'] : null,
            'url' => is_string($data['html_url'] ?? null) ? $data['html_url'] : null,
        ];
    }

    private static function github(): PendingRequest
    {
        $client = Http::baseUrl('https://api.github.com/')
            ->timeout(10)
            ->withUserAgent('NorthdeckPanel-Updates')
            ->accept('application/vnd.github+json');
        $token = config('northdeck.updates.token');

        return is_string($token) && $token !== '' ? $client->withToken($token) : $client;
    }
}
