<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Token;

/**
 * Passport's access token, plus the workspace a personal connector token was
 * created for — see the `add_workspace_id_to_oauth_access_tokens` migration.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string $client_id
 * @property int|null $workspace_id
 * @property string|null $name
 * @property array<int, string>|null $scopes
 * @property bool $revoked
 */
class OAuthToken extends Token
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
