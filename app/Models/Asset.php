<?php

namespace App\Models;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One thing on a shelf in the asset library: a logo, a contract, the deck.
 *
 * The bytes live on a {@see MessageAttachment}, which is where every file in
 * this application lives — see the migration for why the library did not get a
 * storage table of its own. What this row adds is the identity the file has in
 * the library: the name people call it, the note explaining when to use it, and
 * the shelf it is on. Renaming an asset changes none of that file's own
 * properties, which is the point — `Logo_FINAL_v3 (2).svg` keeps being what was
 * uploaded, and "Primary logo — light backgrounds" is what it is called here.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $folder_id
 * @property string $name
 * @property string|null $description
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read AssetFolder|null $folder
 * @property-read MessageAttachment|null $file
 * @property-read User|null $creator
 */
#[Fillable(['workspace_id', 'folder_id', 'name', 'description', 'created_by'])]
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<AssetFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(AssetFolder::class, 'folder_id');
    }

    /**
     * The bytes.
     *
     * A `hasOne` rather than a `belongsTo` because ownership is recorded on the
     * attachment: that is the row the orphan sweep, the composer's tray delete,
     * and the message-send claim all consult, and an asset whose file said
     * nothing about being spoken for would be deleted by the first of those
     * within the day. At most one, enforced by a partial unique index.
     *
     * @return HasOne<MessageAttachment, $this>
     */
    public function file(): HasOne
    {
        return $this->hasOne(MessageAttachment::class, 'asset_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * Assets sitting at the top of the library rather than on a shelf.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeAtRoot(Builder $query): void
    {
        $query->whereNull('folder_id');
    }

    /**
     * The library's type filter, expressed against the file's MIME prefix so the
     * buckets match {@see MessageAttachment::kind()} exactly.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOfKind(Builder $query, string $kind): void
    {
        $query->whereHas(
            'file',
            fn (Builder $file) => MessageAttachment::constrainToKind($file, $kind),
        );
    }
}
