<?php

namespace App\Northdeck\Admin;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Someone an instance admin invited. Until it expires, that email can make an
 * account whatever the sign-up rules say.
 *
 * @property int $id
 * @property string $email
 * @property int|null $invited_by
 * @property int|null $workspace_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $inviter
 * @property-read Workspace|null $workspace
 */
class PanelInvite extends Model
{
    protected $table = 'northdeck_panel_invites';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public static function pendingFor(string $email): bool
    {
        return self::query()->pending()->whereRaw('lower(email) = ?', [Str::lower(trim($email))])->exists();
    }

    /** Marks the email's invites used, once its account exists. */
    public static function accept(User $user): void
    {
        self::query()->pending()->whereRaw('lower(email) = ?', [Str::lower($user->email)])->update(['accepted_at' => now()]);
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }
}
