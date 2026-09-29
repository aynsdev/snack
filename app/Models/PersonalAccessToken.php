<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * A bearer token an AI client uses to reach the MCP server.
 *
 * Sanctum's model with the workspace it was issued for — see the table
 * migration for why that is a column rather than something a tool call names.
 *
 * @property int $id
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property int|null $workspace_id
 * @property string $name
 * @property string $token
 * @property array<int, string>|null $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * The ability every MCP connector token carries, and the one the MCP route
     * requires. A token minted for anything else in the future will not open
     * the MCP server by accident.
     */
    public const MCP_ABILITY = 'mcp:use';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'workspace_id',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Tokens that can still authenticate.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnexpired(Builder $query): void
    {
        $query->where(fn (Builder $group) => $group
            ->whereNull('expires_at')
            ->orWhere('expires_at', '>', now()));
    }

    public function isMcpConnector(): bool
    {
        return $this->workspace_id !== null && $this->can(self::MCP_ABILITY);
    }
}
