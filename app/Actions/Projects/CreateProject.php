<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\ColumnCategory;
use App\Enums\ProjectRole;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SlugGenerator;
use App\Support\Positions;
use Illuminate\Support\Facades\DB;

/**
 * Creates a project, its starting board, and its first member.
 *
 * All three in one transaction, because none of them is optional. A project
 * with no columns cannot hold a task — every task needs a status — and a project
 * with no members is a board nobody, including the person who just made it, is
 * allowed to work.
 */
class CreateProject
{
    /**
     * The board every project starts with.
     *
     * Three columns rather than the seven on the reference board: a team's
     * columns are the part of the board they are most opinionated about, and
     * arriving at a board that already assumes a QA step and a release step is
     * arriving at somebody else's process. These three are the minimum that
     * satisfies the two rules {@see ColumnCategory} exists to answer — where a
     * new task lands, and what counts as finished — and columns are added by
     * dragging one in.
     *
     * @var list<array{name: string, category: ColumnCategory}>
     */
    private const STARTING_COLUMNS = [
        ['name' => 'To do', 'category' => ColumnCategory::Unstarted],
        ['name' => 'In progress', 'category' => ColumnCategory::Started],
        ['name' => 'Done', 'category' => ColumnCategory::Done],
    ];

    public function __construct(
        private readonly SlugGenerator $slugs,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     key?: string|null,
     *     description?: string|null,
     *     color?: string|null,
     *     uses_sprints?: bool,
     *     project_group_id?: int|null,
     * }  $attributes
     */
    public function handle(Workspace $workspace, User $creator, array $attributes): Project
    {
        return DB::transaction(function () use ($workspace, $creator, $attributes): Project {
            $project = Project::create([
                'workspace_id' => $workspace->id,
                'created_by' => $creator->id,
                // Null is the ordinary case: most projects are made before
                // anybody has decided the list needs headings.
                'project_group_id' => $attributes['project_group_id'] ?? null,
                'name' => $attributes['name'],
                'slug' => $this->slugs->forProject($workspace, $attributes['name']),
                // A key the creator typed is honoured; otherwise one is derived
                // from the name, which is what they would have typed anyway.
                'key' => $this->key($workspace, $attributes),
                'description' => $attributes['description'] ?? null,
                'color' => $attributes['color'] ?? 'violet',
                'uses_sprints' => $attributes['uses_sprints'] ?? true,
                'card_fields' => [],
            ]);

            foreach (self::STARTING_COLUMNS as $index => $column) {
                BoardColumn::create([
                    'project_id' => $project->id,
                    'name' => $column['name'],
                    'category' => $column['category'],
                    'position' => ($index + 1) * Positions::GAP,
                ]);
            }

            ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $creator->id,
                'role' => ProjectRole::Lead,
                'joined_at' => now(),
            ]);

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::ProjectCreated,
                actor: $creator,
                auditable: $project,
                newValues: [
                    'name' => $project->name,
                    'key' => $project->key,
                    'uses_sprints' => $project->uses_sprints,
                ],
            );

            return $project;
        });
    }

    /**
     * @param  array{name: string, key?: string|null}  $attributes
     */
    private function key(Workspace $workspace, array $attributes): string
    {
        $requested = trim((string) ($attributes['key'] ?? ''));

        return $requested === ''
            ? $this->slugs->forProjectKey($workspace, $attributes['name'])
            : strtoupper($requested);
    }
}
