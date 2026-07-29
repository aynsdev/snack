<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An invitation to join a workspace.
 *
 * The `token` column stores a SHA-256 hash of the value that appears in the
 * invite URL. The plaintext exists only in memory at creation time and in the
 * emailed link, so a database leak alone cannot be replayed into access.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $invited_by
 * @property string $email
 * @property WorkspaceRole $role
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable([
    'workspace_id', 'invited_by', 'email', 'role', 'token',
    'expires_at', 'accepted_at', 'accepted_by', 'revoked_at', 'revoked_by',
])]
#[Hidden(['token'])]
class WorkspaceInvitation extends Model
{
    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    /**
     * The plaintext invitation token, available only on the instance that just
     * issued it.
     *
     * A real PHP property rather than a model attribute: an attribute would be
     * included in the next INSERT or UPDATE and fail against a column that does
     * not exist — and, worse, would mean the plaintext was one stray save()
     * away from being persisted.
     */
    public ?string $plainToken = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    // -- Tokens --------------------------------------------------------------

    /**
     * Generate a plaintext invitation token. Store {@see self::hashToken()} of
     * this value; hand the plaintext to the recipient exactly once.
     */
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /**
     * Hash a plaintext token for storage and lookup. SHA-256 (rather than a
     * slow password hash) keeps the lookup a single indexed equality check,
     * which is safe because the token is 48 random characters, not a secret a
     * human chose.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Find a live invitation by its plaintext token.
     */
    public static function findByToken(string $plainToken): ?self
    {
        return self::query()
            ->where('token', self::hashToken($plainToken))
            ->first();
    }

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * Neither accepted nor revoked nor expired.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Accepted and revoked invitations are excluded; expired ones are not, so
     * the admin UI can show them as expired rather than silently dropping them.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    // -- State ---------------------------------------------------------------

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Whether the invitation can still be redeemed right now.
     */
    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Human-readable state for the admin invitations table.
     */
    public function status(): string
    {
        return match (true) {
            $this->isAccepted() => 'accepted',
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            default => 'pending',
        };
    }

    /**
     * Whether the invitation was addressed to this account. Invitations are
     * bound to an email so a forwarded link cannot be redeemed by someone else.
     */
    public function isAddressedTo(User $user): bool
    {
        return mb_strtolower($this->email) === mb_strtolower($user->email);
    }
}
