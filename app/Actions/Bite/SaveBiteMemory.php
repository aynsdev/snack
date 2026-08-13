<?php

namespace App\Actions\Bite;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Models\BiteMemory;
use App\Models\User;
use App\Models\Workspace;

/**
 * Writes down, edits, or removes one thing Bite knows.
 *
 * Create and update are one method because they are one form and one decision:
 * every field is settable at both ends, and splitting them would have meant two
 * copies of the same trimming rules drifting apart.
 *
 * Deleting is separate, because it is the only one that destroys something —
 * and because the audit entry has to be written *before* the row goes, while
 * there is still a title to record.
 */
class SaveBiteMemory
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /**
     * @param  array{title: string, body: string, is_enabled?: bool}  $attributes
     */
    public function create(Workspace $workspace, User $actor, array $attributes): BiteMemory
    {
        $memory = BiteMemory::create([
            ...$attributes,
            'workspace_id' => $workspace->id,
            'created_by' => $actor->id,
            'position' => BiteMemory::nextPosition($workspace),
        ]);

        $this->audit->handle(
            workspace: $workspace,
            action: AuditAction::BiteMemoryCreated,
            actor: $actor,
            auditable: $memory,
            newValues: $this->snapshot($memory),
        );

        return $memory;
    }

    /**
     * @param  array{title?: string, body?: string, is_enabled?: bool, position?: int}  $attributes
     */
    public function update(BiteMemory $memory, User $actor, array $attributes): BiteMemory
    {
        $before = $this->snapshot($memory);

        $memory->fill($attributes);

        if (! $memory->isDirty()) {
            return $memory;
        }

        $memory->save();

        $this->audit->handle(
            workspace: $memory->workspace,
            action: AuditAction::BiteMemoryUpdated,
            actor: $actor,
            auditable: $memory,
            oldValues: $before,
            newValues: $this->snapshot($memory),
        );

        return $memory;
    }

    public function delete(BiteMemory $memory, User $actor): void
    {
        /*
         * Recorded first. Afterwards the row is gone and with it the title,
         * which is the only part of the entry anybody will read — an audit line
         * saying an id was deleted answers nothing.
         */
        $this->audit->handle(
            workspace: $memory->workspace,
            action: AuditAction::BiteMemoryDeleted,
            actor: $actor,
            auditable: $memory,
            oldValues: $this->snapshot($memory),
        );

        $memory->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BiteMemory $memory): array
    {
        return [
            'title' => $memory->title,
            /*
             * The body in full, not a summary. "What exactly was Bite telling
             * people last week" is the question this trail exists for, and a
             * truncated answer to it is no answer.
             */
            'body' => $memory->body,
            'is_enabled' => $memory->is_enabled,
        ];
    }
}
