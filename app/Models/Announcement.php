<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A company-wide notice from HR or the workspace's admins.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $author_id
 * @property int|null $channel_id
 * @property int|null $message_id
 * @property string $title
 * @property string $body
 * @property bool $is_pinned
 * @property bool $notified_everyone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $author
 * @property-read Channel|null $channel
 * @property-read Message|null $message
 */
#[Fillable(['workspace_id', 'author_id', 'channel_id', 'message_id', 'title', 'body', 'is_pinned', 'notified_everyone'])]
class Announcement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'notified_everyone' => 'boolean',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The channel it was posted to, if any.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * The channel post itself, while it still exists.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
