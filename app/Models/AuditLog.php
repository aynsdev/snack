<?php

namespace App\Models;

use App\Enums\AuditAction;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only record of workspace administration.
 *
 * Rows are never updated or deleted by the application; retention is handled by
 * a scheduled prune using the workspace's configured window.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property AuditAction $action
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
#[Fillable([
    'workspace_id', 'user_id', 'action', 'auditable_type', 'auditable_id',
    'old_values', 'new_values', 'ip_address', 'user_agent',
])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
        ];
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
     * The actor. Nullable so the entry survives the deletion of the user who
     * performed it, which is exactly when an audit trail matters most.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWhereAction(Builder $query, AuditAction ...$actions): void
    {
        $query->whereIn('action', array_map(fn (AuditAction $action): string => $action->value, $actions));
    }

    /**
     * Filter by the `subject` prefix of the action, e.g. "member" or "channel".
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWhereSubject(Builder $query, string $subject): void
    {
        $query->where('action', 'like', $subject.'.%');
    }
}
