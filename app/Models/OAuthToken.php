<?php

namespace App\Models;

use App\Enums\ConnectorKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Token;

/**
 * Passport's access token, plus the workspace a personal connector token was
 * created for — see the `add_workspace_id_to_oauth_access_tokens` migration —
 * and what kind of connector it is.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $client_id
 * @property int|null $workspace_id
 * @property ConnectorKind $connector_kind
 * @property string|null $name
 * @property array<int, string>|null $scopes
 * @property bool $revoked
 */
class OAuthToken extends Token
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'connector_kind' => ConnectorKind::class,
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
