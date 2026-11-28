<?php

namespace App\Northdeck\Updates;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One update to a commit of Northdeck's repository.
 *
 * @property int $id
 * @property string $commit
 * @property string|null $from_commit
 * @property string $status
 * @property bool $auto
 * @property int|null $requested_by
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $requester
 */
class SourceUpdate extends Model
{
    public const string REQUESTED = 'requested';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    protected $table = 'northdeck_updates';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['auto' => 'boolean', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function fail(string $error): void
    {
        $this->forceFill(['status' => self::FAILED, 'error' => mb_substr($error, 0, 2000), 'finished_at' => now()])->save();
    }
}
