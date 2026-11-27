<?php

namespace App\Northdeck\Sso\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person at an identity provider, keyed on (connection, subject). The email
 * is kept for display only: we never look people up by it once linked.
 *
 * @property int $id
 * @property int $connection_id
 * @property string $subject
 * @property int $user_id
 * @property string|null $email
 * @property CarbonImmutable|null $last_sign_in_at
 * @property-read SsoConnection $connection
 * @property-read User $user
 */
class SsoIdentity extends Model
{
    protected $table = 'northdeck_sso_identities';

    protected $fillable = ['connection_id', 'subject', 'user_id', 'email', 'last_sign_in_at'];

    protected function casts(): array
    {
        return ['last_sign_in_at' => 'datetime'];
    }

    /** @return BelongsTo<SsoConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SsoConnection::class, 'connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
