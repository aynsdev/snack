<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Hr\PublishAnnouncement;
use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\AnnouncementRequest;
use App\Http\Resources\Hr\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Channel;
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
    public function index(Workspace $workspace, Request $request): JsonResponse
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
                'channels' => $canManage ? $this->postableChannels($workspace, $request) : [],
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

    /**
     * The live channels the admin is in, announcement channels first, then
     * the default ones everybody joins — the same order the web picker uses.
     *
     * @return array<int, array{id: int, name: string, type: string, is_default: bool}>
     */
    private function postableChannels(Workspace $workspace, Request $request): array
    {
        return $workspace->channels()
            ->notArchived()
            ->whereHas('members', fn ($query) => $query->where('user_id', $request->user()->id))
            ->get(['id', 'name', 'is_default', 'type'])
            ->sortBy(fn (Channel $channel): string => sprintf(
                '%d-%d-%s',
                $channel->type === ChannelType::Announcement ? 0 : 1,
                $channel->is_default ? 0 : 1,
                $channel->name,
            ))
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type->value,
                'is_default' => $channel->is_default,
            ])
            ->values()
            ->all();
    }
}
