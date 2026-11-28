<?php

namespace App\Northdeck\Updates;

use Illuminate\Console\Command;

/** northdeck:update-finished, run by the server's updater once it's done. */
final class FinishUpdate extends Command
{
    protected $signature = 'northdeck:update-finished {id} {--failed= : Why it didn’t work}';

    protected $description = 'For the server’s updater: report how a Northdeck update went';

    public function handle(): int
    {
        $update = SourceUpdate::query()->find((int) $this->argument('id'));
        if (! $update instanceof SourceUpdate) {
            $this->error('No such update.');

            return self::FAILURE;
        }

        $failed = $this->option('failed');
        SourceUpdates::finish($update, is_string($failed) && $failed !== '' ? $failed : null);
        $this->line($update->refresh()->status);

        return self::SUCCESS;
    }
}
