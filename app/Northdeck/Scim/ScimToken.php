<?php

namespace App\Northdeck\Scim;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $token_hash
 * @property CarbonImmutable|null $last_used_at
 */
class ScimToken extends Model
{
    protected $table = 'northdeck_scim_tokens';

    protected $fillable = ['workspace_id', 'token_hash', 'last_used_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /** @return array{0: self, 1: string} the token and its secret, shown once */
    public static function issue(Workspace $workspace): array
    {
        $secret = 'ndscim_'.Str::random(48);
        $token = self::query()->updateOrCreate(['workspace_id' => $workspace->id], ['token_hash' => hash('sha256', $secret), 'last_used_at' => null]);

        return [$token, $secret];
    }

    public static function findBySecret(string $secret): ?self
    {
        return self::query()->where('token_hash', hash('sha256', $secret))->first();
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
