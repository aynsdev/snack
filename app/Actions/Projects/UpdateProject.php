<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;
use App\Services\SlugGenerator;

/**
 * Renames a project and edits its settings.
 *
 * # What a rename does and does not change
 *
 * The slug is regenerated, because it is what the URL carries and a project
 * called something else should not still be addressed by its old name.
 *
 * The key is not — not automatically, and not without the caller asking. It is
 * baked into every reference anyone has ever quoted: PWA-1499 written in a
 * channel last month, in a commit message, in somebody's notes. Changing it
 * silently would invalidate all of them at once. A caller that does pass a key
 * has said so deliberately, and the unique constraint still has the last word.
 */
class UpdateProject
{
    public function __construct(
        private readonly SlugGenerator $slugs,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  array{
     *     name?: string,
     *     key?: string,
     *     description?: string|null,
     *     color?: string,
     *     uses_sprints?: bool,
     *     card_fields?: array<string, bool>,
     * }  $attributes
     */
    public function handle(Project $project, User $actor, array $attributes): Project
    {
        $before = $project->only(['name', 'key', 'description', 'color', 'uses_sprints', 'card_fields']);

        if (array_key_exists('name', $attributes) && $attributes['name'] !== $project->name) {
            $attributes['slug'] = $this->slugs->forProject(
                $project->workspace,
                $attributes['name'],
                ignoreId: $project->id,
            );
        }

        if (array_key_exists('key', $attributes)) {
            $attributes['key'] = strtoupper($attributes['key']);
        }

        if (array_key_exists('card_fields', $attributes)) {
            // Stored as the project's overrides rather than the resolved set, so
            // a toggle added in a later release still picks up its default here
            // instead of being frozen false — see Project::cardFields().
            $attributes['card_fields'] = array_intersect_key(
                array_map(static fn (mixed $value): bool => (bool) $value, $attributes['card_fields']),
                Project::CARD_FIELD_DEFAULTS,
            );
        }

        $project->fill($attributes);

        if ($project->isDirty()) {
            $project->save();

            $this->audit->handle(
                workspace: $project->workspace,
                action: AuditAction::ProjectUpdated,
                actor: $actor,
                auditable: $project,
                oldValues: $before,
                newValues: $project->only(array_keys($before)),
            );
        }

        return $project;
    }
}
