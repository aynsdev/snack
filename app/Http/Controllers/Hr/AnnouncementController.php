<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Hr\PublishAnnouncement;
use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\AnnouncementRequest;
use App\Http\Resources\Hr\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('viewAny', [Announcement::class, $workspace]);

        $announcements = $workspace->announcements()
            ->with(['author', 'channel'])
            ->orderByDesc('is_pinned')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $canManage = $request->user()->can('create', [Announcement::class, $workspace]);

        return Inertia::render('hr/announcements', [
            'announcements' => AnnouncementResource::collection($announcements),
            'can_manage' => $canManage,
            'channels' => $canManage ? $this->postableChannels($workspace, $request) : [],
        ]);
    }

    public function store(
        Workspace $workspace,
        AnnouncementRequest $request,
        PublishAnnouncement $publish,
    ): RedirectResponse {
        $channel = $request->channel();

        $publish->publish(
            workspace: $workspace,
            author: $request->user(),
            attributes: $request->announcementAttributes(),
            channel: $channel,
            notifyEveryone: $request->boolean('notify_everyone'),
        );

        return back()->with('success', $channel === null
            ? 'Announcement published.'
            : "Announcement posted to #{$channel->name}.");
    }

    public function update(
        Workspace $workspace,
        Announcement $announcement,
        AnnouncementRequest $request,
        PublishAnnouncement $publish,
    ): RedirectResponse {
        $publish->revise($announcement, $request->announcementAttributes());

        return back()->with('success', 'Announcement updated.');
    }

    public function destroy(
        Workspace $workspace,
        Announcement $announcement,
        Request $request,
        PublishAnnouncement $publish,
    ): RedirectResponse {
        $this->authorize('delete', $announcement);

        $publish->withdraw($announcement, $request->user());

        return back()->with('success', 'Announcement deleted.');
    }

    /**
     * The channels the admin may post to: live ones they are in.
     *
     * Announcement-type channels lead — they exist for exactly this, and only
     * admins can post in them — then the workspace's default channels, which
     * everybody joins, so a notice there reaches the whole company.
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
