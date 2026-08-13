<?php

// CarbonImmutable rather than Carbon: see the note in App\Models\Huddle.

namespace App\Models;

use App\Enums\BiteAudience;
use App\Enums\BiteCapability;
use App\Enums\BiteChannelMode;
use Carbon\CarbonImmutable;
use Database\Factories\BiteSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One workspace's configuration for Bite.
 *
 * Read on the path that answers a message, so everything here is designed to be
 * answered from one row with no further queries: the channel guard is a list of
 * ids rather than a relation, and the capabilities are strings rather than
 * models.
 *
 * See the table migration for why this is not part of `workspaces.settings`, and
 * for the rule that nothing here can widen what Bite is allowed to know.
 *
 * @property int $id
 * @property int $workspace_id
 * @property bool $is_enabled
 * @property BiteAudience $audience
 * @property BiteChannelMode $channel_mode
 * @property array<int, int> $channel_ids
 * @property string|null $instructions
 * @property int|null $context_messages
 * @property int|null $max_steps
 * @property array<int, string>|null $capabilities
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $editor
 */
#[Fillable([
    'workspace_id', 'is_enabled', 'audience', 'channel_mode', 'channel_ids',
    'instructions', 'context_messages', 'max_steps', 'capabilities', 'updated_by',
])]
class BiteSetting extends Model
{
    /** @use HasFactory<BiteSettingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'audience' => BiteAudience::class,
            'channel_mode' => BiteChannelMode::class,
            'channel_ids' => 'array',
            'capabilities' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

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
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * This workspace's settings, or the defaults it is running on.
     *
     * Deliberately does not create the row. Reading a configuration screen — or
     * answering a message — must not write, both because it happens on a queue
     * worker inside a transaction it does not own and because the difference
     * between "never configured" and "configured to the defaults" is worth
     * keeping. The row appears when somebody saves.
     *
     * The unsaved instance is a real model with the schema's own defaults, so
     * every caller reads the same shape and none of them needs a null branch.
     */
    public static function forWorkspace(Workspace $workspace): self
    {
        return $workspace->biteSetting ?? new self([
            'workspace_id' => $workspace->id,
            'is_enabled' => true,
            'audience' => BiteAudience::Everyone,
            'channel_mode' => BiteChannelMode::All,
            'channel_ids' => [],
            'capabilities' => null,
        ]);
    }

    /**
     * Whether Bite may answer in this channel.
     *
     * Takes an id rather than a Channel because the caller has one and loading
     * the model to read its primary key would be a query to answer a question
     * about an array.
     */
    public function admitsChannel(int $channelId): bool
    {
        return $this->channel_mode->admits($channelId, $this->guardedChannelIds());
    }

    /**
     * The channels the guard names, as ints.
     *
     * Cleaned on read rather than trusted: the column is JSON written by a form,
     * and a stale id left behind by a deleted channel must not become a string
     * comparison that silently never matches.
     *
     * @return array<int, int>
     */
    public function guardedChannelIds(): array
    {
        return array_values(array_map(
            fn ($id): int => (int) $id,
            array_filter($this->channel_ids ?? [], 'is_numeric'),
        ));
    }

    /**
     * The capabilities that are switched on.
     *
     * Null means "everything", which is what an unconfigured workspace gets —
     * see the migration for why the stored form is an allow-list. Unknown
     * strings are dropped rather than tolerated, so a capability removed from a
     * later release cannot resurrect itself through a stale row.
     *
     * @return array<int, BiteCapability>
     */
    public function enabledCapabilities(): array
    {
        $stored = $this->capabilities;

        if ($stored === null) {
            return BiteCapability::cases();
        }

        return array_values(array_filter(array_map(
            BiteCapability::tryFrom(...),
            $stored,
        )));
    }

    public function allows(BiteCapability $capability): bool
    {
        return in_array($capability, $this->enabledCapabilities(), true);
    }

    /**
     * How many messages of the surrounding conversation Bite is given here.
     */
    public function contextMessages(): int
    {
        return $this->context_messages ?? (int) config('snack.bite.context_messages', 20);
    }

    /**
     * The ceiling on one answer's tool-calling loop.
     */
    public function maxSteps(): int
    {
        return $this->max_steps ?? (int) config('snack.bite.max_steps', 8);
    }
}
