<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The audit log, read-only and admin-gated — the mirror of
 * {@see \App\Http\Controllers\Workspaces\AuditLogController}, filtered by
 * subject (`?subject=member`) and paged by number (`?page=`), newest first.
 */
class AuditLogController extends Controller
{
    public function index(Workspace $workspace, Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAuditLogs', $workspace);

        $subjects = collect(AuditAction::cases())
            ->map(fn (AuditAction $action): string => $action->subject())
            ->unique()
            ->values()
            ->all();

        // Validated against the enum rather than trusted: it reaches a LIKE.
        $subject = $request->query('subject');
        $subject = is_string($subject) && in_array($subject, $subjects, true) ? $subject : null;

        $entries = AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->when($subject !== null, fn ($query) => $query->whereSubject($subject))
            ->with('user')
            // By id, not timestamp: rows from one transaction share a timestamp.
            ->orderByDesc('id')
            ->paginate((int) config('snack.audit.page_size', 50))
            ->withQueryString();

        return AuditLogResource::collection($entries)->additional([
            'subject' => $subject,
            'subjects' => $subjects,
        ]);
    }
}
