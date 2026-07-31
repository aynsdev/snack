<?php

namespace App\Http\Controllers\Messages;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContainerSummary;
use App\Http\Resources\MessageResource;
use App\Models\Mention;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Everything addressed to you in this workspace.
 *
 * A history rather than a queue: rows are stamped read, never deleted, so this
 * still answers "what was I asked about last week" after the badge has cleared.
 *
 * Scoped by `mentioned_user_id`, which is the only scoping it needs — a mention
 * row exists precisely because {@see App\Actions\Messages\SyncMentions} already
 * established that this person could see the message when it was written.
 * Messages since deleted are excluded rather than rendered as a gap.
 */
class MentionController extends Controller
{
    public function index(Workspace $workspace, Request $request): InertiaResponse
    {
        $mentions = Mention::query()
            ->for($request->user(), $workspace)
            ->whereHas('message')
            ->with([
                'message' => fn ($query) => $query->withTimelineRelations()->with([
                    'channel:id,name,slug,type,workspace_id',
                    'conversation',
                ]),
            ])
            // Newest first, by the mention's own id: it is monotonic, and two
            // mentions written in the same instant would otherwise sort at random.
            ->orderByDesc('id')
            ->limit((int) config('snack.mentions.list_limit', 50))
            ->get();

        return Inertia::render('mentions/index', [
            /*
             * No null guard on `message`: `whereHas` runs through the relation,
             * which carries Message's soft-delete scope, so a mention whose
             * message was deleted is already excluded from the query.
             */
            'mentions' => $mentions
                ->map(fn (Mention $mention): array => [
                    'id' => $mention->id,
                    'type' => $mention->mention_type->value,
                    'is_read' => $mention->isRead(),
                    'message' => (new MessageResource($mention->message))->resolve($request),
                    'container' => ContainerSummary::for($mention->message),
                ])
                ->values()
                ->all(),
        ]);
    }
}
