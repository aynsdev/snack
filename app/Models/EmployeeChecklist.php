<?php

namespace App\Models;

use App\Enums\ChecklistKind;
use App\Enums\ChecklistStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person's onboarding or offboarding: the steps copied from a template,
 * each with a person to do it and a day it is due.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $employee_id
 * @property ChecklistKind $kind
 * @property string $name
 * @property ChecklistStatus $status
 * @property Carbon $starts_on
 * @property Carbon|null $target_on
 * @property int|null $started_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Employee $employee
 * @property-read User|null $starter
 */
#[Fillable(['workspace_id', 'employee_id', 'kind', 'name', 'status', 'starts_on', 'target_on', 'started_by', 'completed_at'])]
class EmployeeChecklist extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ChecklistKind::class,
            'status' => ChecklistStatus::class,
            'starts_on' => 'date',
            'target_on' => 'date',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @return HasMany<EmployeeChecklistTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(EmployeeChecklistTask::class)->orderBy('position');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInProgress(Builder $query): void
    {
        $query->where('status', ChecklistStatus::InProgress);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOfKind(Builder $query, ChecklistKind $kind): void
    {
        $query->where('kind', $kind);
    }
}
