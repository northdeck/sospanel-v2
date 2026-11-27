<?php

namespace App\Northdeck\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $connection_id
 * @property string $group
 * @property string $role
 */
class SsoGroupMapping extends Model
{
    protected $table = 'northdeck_sso_group_mappings';

    protected $fillable = ['connection_id', 'group', 'role'];

    /** @return BelongsTo<SsoConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SsoConnection::class, 'connection_id');
    }
}
