<?php

namespace App\Models;

use App\Enums\ChecklistAssignee;
use App\Enums\ChecklistKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A workspace's list of onboarding or offboarding steps, copied onto a
 * person's own {@see EmployeeChecklist} when one is started.
 *
 * @property int $id
 * @property int $workspace_id
 * @property ChecklistKind $kind
 * @property string $name
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 */
#[Fillable(['workspace_id', 'kind', 'name', 'is_default'])]
class ChecklistTemplate extends Model
{
    /**
     * What a workspace starts with, so the feature works before anybody
     * writes a template of their own.
     *
     * @var array<string, array{name: string, tasks: array<int, array{0: string, 1: ChecklistAssignee, 2: int}>}>
     */
    public const DEFAULTS = [
        'onboarding' => [
            'name' => 'Standard onboarding',
            'tasks' => [
                ['Prepare laptop and accounts', ChecklistAssignee::Hr, -2],
                ['Upload signed contract', ChecklistAssignee::Hr, 0],
                ['Welcome meeting and team introductions', ChecklistAssignee::Manager, 0],
                ['Read the company policies', ChecklistAssignee::Employee, 3],
                ['Fill in personal and emergency contact details', ChecklistAssignee::Employee, 3],
                ['First-week check-in', ChecklistAssignee::Manager, 5],
                ['End-of-probation review', ChecklistAssignee::Manager, 90],
            ],
        ],
        'offboarding' => [
            'name' => 'Standard offboarding',
            'tasks' => [
                ['Agree a handover plan', ChecklistAssignee::Manager, -14],
                ['Hand over work and documents', ChecklistAssignee::Employee, -2],
                ['Exit interview', ChecklistAssignee::Hr, -1],
                ['Return company assets', ChecklistAssignee::Employee, 0],
                ['Revoke system access', ChecklistAssignee::Hr, 0],
                ['Final pay and clearance', ChecklistAssignee::Hr, 7],
            ],
        ],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ChecklistKind::class,
            'is_default' => 'boolean',
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
     * @return HasMany<ChecklistTemplateTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ChecklistTemplateTask::class)->orderBy('position');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOfKind(Builder $query, ChecklistKind $kind): void
    {
        $query->where('kind', $kind);
    }

    /**
     * Gives a workspace that has no templates yet the two defaults.
     */
    public static function seedDefaultsFor(Workspace $workspace): void
    {
        if ($workspace->checklistTemplates()->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $kind => $template) {
            $created = $workspace->checklistTemplates()->create([
                'kind' => $kind,
                'name' => $template['name'],
                'is_default' => true,
            ]);

            foreach ($template['tasks'] as $position => [$title, $assignee, $offset]) {
                $created->tasks()->create([
                    'title' => $title,
                    'assignee_role' => $assignee,
                    'due_offset_days' => $offset,
                    'position' => $position,
                ]);
            }
        }
    }

    /**
     * The template to use when nobody picks one.
     */
    public static function defaultFor(Workspace $workspace, ChecklistKind $kind): ?self
    {
        return $workspace->checklistTemplates()->ofKind($kind)->orderByDesc('is_default')->oldest('id')->first();
    }
}
