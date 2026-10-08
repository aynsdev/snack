<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * HR asking several people for the same paperwork — an ID, a certificate, a
 * signed form. Each person answers with their own {@see DocumentSubmission}.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $title
 * @property string|null $description
 * @property array<int, string> $allowed_formats
 * @property int $max_size_mb
 * @property Carbon|null $due_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $creator
 */
#[Fillable(['workspace_id', 'created_by', 'title', 'description', 'allowed_formats', 'max_size_mb', 'due_on'])]
class DocumentRequest extends Model
{
    /** The file types HR may ask for. */
    public const FORMATS = ['pdf', 'doc', 'docx', 'xlsx', 'txt', 'jpg', 'jpeg', 'png'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_formats' => 'array',
            'max_size_mb' => 'integer',
            'due_on' => 'date',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<DocumentSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(DocumentSubmission::class);
    }
}
