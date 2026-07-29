<?php

namespace App\Actions\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes a single, append-only audit entry.
 *
 * Request metadata is read defensively through the `request()` helper so the
 * same action works from a controller, an Artisan command, and a queued job.
 */
class RecordAuditLog
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function handle(
        Workspace $workspace,
        AuditAction $action,
        ?User $actor = null,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
    ): AuditLog {
        return AuditLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues === [] ? null : $oldValues,
            'new_values' => $newValues === [] ? null : $newValues,
            'ip_address' => $this->ipAddress(),
            'user_agent' => $this->userAgent(),
        ]);
    }

    private function ipAddress(): ?string
    {
        return app()->runningInConsole() ? null : request()->ip();
    }

    private function userAgent(): ?string
    {
        if (app()->runningInConsole()) {
            return null;
        }

        return str(request()->userAgent() ?? '')->limit(1000)->toString() ?: null;
    }
}
