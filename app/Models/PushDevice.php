<?php

namespace App\Models;

use App\Enums\PushPlatform;
use Database\Factories\PushDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A phone that receives push notifications — see the `create_push_devices_table`
 * migration for why the token is unique across people.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property PushPlatform $platform
 * @property string|null $device_name
 * @property string|null $oauth_access_token_id
 * @property Carbon $last_registered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'token', 'platform', 'device_name', 'oauth_access_token_id', 'last_registered_at'])]
class PushDevice extends Model
{
    /** @use HasFactory<PushDeviceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => PushPlatform::class,
            'last_registered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
