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
 * @property string|null $email
 * @property WorkspaceRole $role
 * @property int|null $max_uses
 * @property int $use_count
 * @property string $token
 * @property string|null $link_token
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
    'workspace_id', 'invited_by', 'email', 'role', 'token', 'link_token', 'max_uses', 'use_count',
    'expires_at', 'accepted_at', 'accepted_by', 'revoked_at', 'revoked_by',
])]
#[Hidden(['token', 'link_token'])]
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
            'max_uses' => 'integer',
            'use_count' => 'integer',
            // Encrypted at rest: a link has to stay copyable, but a leaked
            // database without APP_KEY still must not be replayable into
            // workspace access. See the migration that added the column.
            'link_token' => 'encrypted',
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
            ->where('expires_at', '>', now())
            // A link that has been redeemed its full number of times is spent,
            // even though nothing set `accepted_at` on it.
            ->where(fn (Builder $limited) => $limited
                ->whereNull('max_uses')
                ->orWhereColumn('use_count', '<', 'max_uses'));
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

    /**
     * Shareable invite links — the ones addressed to nobody.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLinks(Builder $query): void
    {
        $query->whereNull('email');
    }

    /**
     * Invitations emailed to a named address.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAddressed(Builder $query): void
    {
        $query->whereNotNull('email');
    }

    // -- State ---------------------------------------------------------------

    /**
     * Whether this is a shareable link rather than an emailed invitation.
     *
     * The distinction is the absence of an addressee, not a flag column: a flag
     * could disagree with the email, and then two places would have to decide
     * which one to believe.
     */
    public function isLink(): bool
    {
        return $this->email === null;
    }

    /**
     * The URL to hand out, or null when this invitation has none to give.
     *
     * Only a link can answer: an emailed invitation's plaintext token lives
     * solely in the message that carried it, by design, so there is nothing here
     * to rebuild its URL from.
     */
    public function shareableUrl(): ?string
    {
        if (! $this->isLink() || $this->link_token === null) {
            return null;
        }

        return route('invitations.show', ['token' => $this->link_token]);
    }

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
     * Whether a use-limited link has any redemptions left.
     *
     * Always true for an emailed invitation: those are limited by `accepted_at`
     * rather than by a counter, and a `max_uses` of null means unlimited.
     */
    public function hasUsesRemaining(): bool
    {
        return $this->max_uses === null || $this->use_count < $this->max_uses;
    }

    /**
     * Whether the invitation can still be redeemed right now.
     */
    public function isPending(): bool
    {
        return ! $this->isAccepted()
            && ! $this->isRevoked()
            && ! $this->isExpired()
            && $this->hasUsesRemaining();
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
            // Ordered after expiry so a link that ran out *and* timed out reads
            // as expired, which is the reason an admin can actually act on.
            ! $this->hasUsesRemaining() => 'used_up',
            default => 'pending',
        };
    }

    /**
     * Whether the invitation was addressed to this account.
     *
     * An emailed invitation is bound to its address, so a forwarded link cannot
     * be redeemed by somebody else. A shareable link is bound to nobody — being
     * forwarded is the entire point of it — so it answers true for everyone and
     * leans on expiry, `max_uses`, and revocation instead.
     */
    public function isAddressedTo(User $user): bool
    {
        if ($this->isLink()) {
            return true;
        }

        return mb_strtolower((string) $this->email) === mb_strtolower($user->email);
    }
}
