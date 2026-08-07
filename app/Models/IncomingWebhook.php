<?php

// CarbonImmutable rather than Carbon: see the note in App\Models\Huddle.

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IncomingWebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A URL an outside system posts to, which becomes a message in one channel.
 *
 * The token in that URL is the whole credential; see the table migration for
 * why that is enough, and why the channel is a column rather than something a
 * caller may name.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property int|null $created_by
 * @property int|null $bot_user_id
 * @property string $name
 * @property string|null $default_username
 * @property string|null $default_icon
 * @property string $token_hash
 * @property string $token The plaintext, decrypted on read.
 * @property string|null $signing_secret Null when the hook is unsigned, which
 *                                       is the default — see {@see requiresSignature()}.
 * @property CarbonImmutable|null $last_delivered_at
 * @property int $delivery_count
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Channel $channel
 * @property-read User|null $creator
 * @property-read User|null $bot
 */
#[Fillable([
    'workspace_id', 'channel_id', 'created_by', 'bot_user_id', 'name',
    'default_username', 'default_icon', 'token_hash', 'token', 'signing_secret',
    'revoked_at',
])]
/*
 * Hidden so the plaintext cannot leave through a stray `->toArray()`. The admin
 * screen reads it deliberately, through IncomingWebhookResource — the one place
 * it is meant to be published, and only to somebody who has just been authorized
 * to administer the workspace.
 */
#[Hidden(['token', 'token_hash', 'signing_secret'])]
class IncomingWebhook extends Model
{
    /** @use HasFactory<IncomingWebhookFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_count' => 'integer',
            // See the migration: encrypted rather than plain so a leaked
            // database without APP_KEY yields no working URLs.
            'token' => 'encrypted',
            /*
             * Encrypted rather than hashed, unlike `token`. Verifying an HMAC
             * needs the secret itself — a digest of it cannot key the same
             * function — so this is one of the few things Snack stores that it
             * must be able to read back. See the migration that added it.
             */
            'signing_secret' => 'encrypted',
            'last_delivered_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    // -- Tokens --------------------------------------------------------------

    /**
     * A fresh plaintext token.
     *
     * 48 random characters, matching {@see WorkspaceInvitation::generateToken()}
     * — long enough that the lookup below is a lookup and not a target.
     */
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /**
     * Hash a plaintext token for storage and lookup.
     *
     * SHA-256 rather than a password hash, for the reason set out on
     * {@see WorkspaceInvitation::hashToken()}: the token is 48 random
     * characters, not something a human chose, so there is nothing for a slow
     * hash to defend against — and a delivery has to be one indexed equality
     * check, not a bcrypt round on a path a CI job hits every build.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Find a live hook by the token from the URL.
     *
     * Eager-loads what a delivery always needs — the workspace the message is
     * stamped with, the channel it lands in — because this is the one query the
     * hot path gets before it starts posting.
     */
    public static function findLiveByToken(string $plainToken): ?self
    {
        return self::query()
            ->live()
            ->with(['workspace', 'channel'])
            ->where('token_hash', self::hashToken($plainToken))
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
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The account this hook posts as.
     *
     * @return BelongsTo<User, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bot_user_id');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    // -- State ---------------------------------------------------------------

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Whether deliveries to this hook must be signed.
     *
     * Off by default. When on, holding the URL is no longer enough — see
     * {@see App\Services\WebhookSignature}.
     */
    public function requiresSignature(): bool
    {
        return $this->signing_secret !== null;
    }

    /**
     * The URL to paste into the calling system.
     *
     * Re-derived from the row on every read rather than stored, so an
     * installation that moves to a new domain does not go on handing out URLs
     * pointing at its old one.
     */
    public function url(): string
    {
        return route('webhooks.deliver', ['token' => $this->token]);
    }

    /**
     * What this hook's messages are attributed to when a payload does not
     * override it.
     */
    public function displayName(): string
    {
        return $this->default_username ?? $this->name;
    }
}
