<?php

namespace App\Northdeck\Updates;

use Illuminate\Console\Command;

/**
 * northdeck:update-claim, run by the server's updater every minute: prints
 * "<id> <commit> <owner/repo>" for an update to carry out, or nothing.
 */
final class ClaimUpdate extends Command
{
    protected $signature = 'northdeck:update-claim';

    protected $description = 'For the server’s updater: the next Northdeck update to carry out';

    public function handle(): int
    {
        $update = SourceUpdates::claim();
        if ($update !== null) {
            $this->line("{$update->id} {$update->commit} ".SourceUpdates::repo());
        }

        return self::SUCCESS;
    }
}
