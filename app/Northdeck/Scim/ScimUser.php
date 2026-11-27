<?php

namespace App\Northdeck\Scim;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $user_id
 * @property string $user_name
 * @property string|null $external_id
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
class ScimUser extends Model
{
    protected $table = 'northdeck_scim_users';

    protected $fillable = ['workspace_id', 'user_id', 'user_name', 'external_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (ScimUser $u) => $u->uuid = $u->uuid ?: (string) Str::uuid());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<ScimGroup, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ScimGroup::class, 'northdeck_scim_group_members', 'scim_user_id', 'group_id');
    }
}
