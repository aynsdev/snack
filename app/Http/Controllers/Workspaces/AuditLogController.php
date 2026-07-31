<?php

namespace App\Http\Controllers\Workspaces;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * What has been done to this workspace, and by whom.
 *
 * Read-only and admin-gated — see WorkspacePolicy::viewAuditLogs. There is no
 * write path here at all: entries are written by
 * {@see App\Actions\Audit\RecordAuditLog} from the actions that cause them, and
 * the table is append-only.
 *
 * # Filtering by subject rather than by action
 *
 * Actions are named `subject.verb` precisely so this screen can filter by
 * prefix. "Show me everything that happened to members" is the question an
 * administrator actually arrives with; "show me `member.role_changed`
 * specifically" is one they can answer by looking at the seventeen rows that
 * returns.
 *
 * Paginated rather than capped like the message inboxes: this is the one view in
 * Snack whose whole purpose is reaching back, so an arbitrary limit would defeat
 * it.
 */
class AuditLogController extends Controller
{
    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        $this->authorize('viewAuditLogs', $workspace);

        $subject = $this->subject($request);

        $entries = AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->when($subject !== null, fn ($query) => $query->whereSubject($subject))
            // Eager-loaded so a page of entries costs one extra query rather
            // than one per row; nullable, because an entry outlives its actor.
            ->with('user')
            /*
             * By id, not by `created_at`. Two entries written in the same
             * transaction share a timestamp to the microsecond, and ordering on
             * it would shuffle them at random between page loads — which on a
             * log is the one thing that makes it untrustworthy.
             */
            ->orderByDesc('id')
            ->paginate((int) config('snack.audit.page_size', 50))
            ->withQueryString();

        return Inertia::render('workspace/audit', [
            'entries' => AuditLogResource::collection($entries)->response()->getData(true),
            'subject' => $subject,

            /*
             * The subjects that exist in the enum, not the ones present in this
             * workspace's rows. A filter that appears only once something has
             * happened cannot be used to confirm that nothing has.
             */
            'subjects' => collect(AuditAction::cases())
                ->map(fn (AuditAction $action): string => $action->subject())
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    /**
     * The requested subject filter, validated against the enum rather than
     * trusted — it reaches a LIKE pattern, and it arrives in a query string.
     */
    private function subject(Request $request): ?string
    {
        $subject = $request->query('subject');

        if (! is_string($subject) || $subject === '') {
            return null;
        }

        $known = array_map(
            fn (AuditAction $action): string => $action->subject(),
            AuditAction::cases(),
        );

        return in_array($subject, $known, true) ? $subject : null;
    }
}
