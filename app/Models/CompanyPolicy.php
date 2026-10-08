<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A page of the company handbook — leave policy, code of conduct — that
 * everybody can read and, when HR asks, has to confirm they have read.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $author_id
 * @property string $title
 * @property string $body
 * @property string|null $file_path
 * @property string|null $file_name
 * @property bool $requires_acknowledgement
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $author
 */
#[Fillable(['workspace_id', 'author_id', 'title', 'body', 'file_path', 'file_name', 'requires_acknowledgement'])]
class CompanyPolicy extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_acknowledgement' => 'boolean',
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
     * @return HasMany<PolicyAcknowledgement, $this>
     */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgement::class);
    }
}
