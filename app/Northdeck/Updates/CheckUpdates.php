<?php

namespace App\Northdeck\Updates;

use Illuminate\Console\Command;

/** northdeck:check-updates: hourly, and auto-update when it's due. */
final class CheckUpdates extends Command
{
    protected $signature = 'northdeck:check-updates';

    protected $description = 'Check Northdeck’s repository for a newer commit (and auto-update if it’s on)';

    public function handle(): int
    {
        $check = SourceUpdates::check();
        if (! $check['reached']) {
            $this->warn((string) ($check['error'] ?? 'Couldn’t reach GitHub.'));

            return self::FAILURE;
        }

        $available = SourceUpdates::available();
        $this->line($available === null ? 'This panel runs the newest commit.' : 'Commit '.substr($available, 0, 7).' is out.');

        if (($update = SourceUpdates::autoUpdate()) !== null) {
            $this->line('Auto-update asked for '.substr($update->commit, 0, 7).'.');
        }

        return self::SUCCESS;
    }
}
