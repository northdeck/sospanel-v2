<?php

namespace App\Northdeck\Scim;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $display_name
 * @property string|null $external_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ScimGroup extends Model
{
    protected $table = 'northdeck_scim_groups';

    protected $fillable = ['workspace_id', 'display_name', 'external_id'];

    protected static function booted(): void
    {
        static::creating(fn (ScimGroup $g) => $g->uuid = $g->uuid ?: (string) Str::uuid());
    }

    /** @return BelongsToMany<ScimUser, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(ScimUser::class, 'northdeck_scim_group_members', 'group_id', 'scim_user_id');
    }
}
