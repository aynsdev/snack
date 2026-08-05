<?php

namespace App\Models;

use App\Enums\ProjectRole;
use Database\Factories\ProjectMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A user's membership of one project.
 *
 * This row is what makes somebody assignable: the picker on a card lists these
 * people and the server re-checks the same set on save, so an id typed into a
 * request cannot put work on a colleague who is not on the project.
 *
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property ProjectRole $role
 * @property Carbon $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read User $user
 */
#[Fillable(['project_id', 'user_id', 'role', 'joined_at'])]
class ProjectMember extends Pivot
{
    /** @use HasFactory<ProjectMemberFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'project_members';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ProjectRole::class,
            'joined_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLead(): bool
    {
        return $this->role->canManageProject();
    }
}
