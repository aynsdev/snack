<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Hr\PublishAnnouncement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\AnnouncementRequest;
use App\Http\Resources\Hr\AnnouncementResource;
use App\Models\Announcement;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(Workspace $workspace, Request $request, PublishAnnouncement $publish): Response
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
            'channels' => $canManage ? $publish->postableChannels($workspace, $request->user()) : [],
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
}
