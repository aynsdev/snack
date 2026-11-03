<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\PublishAnnouncement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\AnnouncementRequest;
use App\Http\Resources\Hr\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Company announcements for the mobile app.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\AnnouncementController}:
 * publishing, revising, and withdrawing all go through PublishAnnouncement, so
 * a channel post stays in step with its announcement exactly as on the web.
 */
class AnnouncementController extends Controller
{
    /**
     * Pinned first, then newest, 20 a page (`?page=`). `meta.can_manage` and
     * `meta.channels` — where an admin may post — ride alongside.
     */
    public function index(Workspace $workspace, Request $request, PublishAnnouncement $publish): JsonResponse
    {
        $this->authorize('viewAny', [Announcement::class, $workspace]);

        $announcements = $workspace->announcements()
            ->with(['author', 'channel'])
            ->orderByDesc('is_pinned')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $canManage = $request->user()->can('create', [Announcement::class, $workspace]);

        return AnnouncementResource::collection($announcements)
            ->additional(['meta' => [
                'can_manage' => $canManage,
                'channels' => $canManage ? $publish->postableChannels($workspace, $request->user()) : [],
            ]])
            ->response();
    }

    public function store(Workspace $workspace, AnnouncementRequest $request, PublishAnnouncement $publish): JsonResponse
    {
        $announcement = $publish->publish(
            workspace: $workspace,
            author: $request->user(),
            attributes: $request->announcementAttributes(),
            channel: $request->channel(),
            notifyEveryone: $request->boolean('notify_everyone'),
        );

        return (new AnnouncementResource($announcement->fresh(['author', 'channel'])))->response()->setStatusCode(201);
    }

    public function update(
        Workspace $workspace,
        Announcement $announcement,
        AnnouncementRequest $request,
        PublishAnnouncement $publish,
    ): AnnouncementResource {
        $publish->revise($announcement, $request->announcementAttributes());

        return new AnnouncementResource($announcement->fresh(['author', 'channel']));
    }

    public function destroy(
        Workspace $workspace,
        Announcement $announcement,
        Request $request,
        PublishAnnouncement $publish,
    ): Response {
        $this->authorize('delete', $announcement);

        $publish->withdraw($announcement, $request->user());

        return response()->noContent();
    }
}
