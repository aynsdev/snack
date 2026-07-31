<?php

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The people you can mention in a channel.
 *
 * Answers with JSON because the composer filters as you type, and an Inertia
 * visit per keystroke would replace the page's props on every character.
 *
 * # Scoped to the channel, deliberately
 *
 * Only members are offered, because only members can be mentioned — see
 * {@see App\Actions\Messages\SyncMentions}, which resolves handles against the
 * container rather than against the users table. A picker that offered people
 * the server would then ignore would be worse than no picker: the author would
 * believe they had notified someone.
 *
 * Gated on `post` rather than `view`. Someone previewing a public channel they
 * have not joined cannot write in it, so they have no use for its roster — and
 * that keeps this from becoming a membership directory for channels the viewer
 * is merely allowed to look at.
 */
class MentionableController extends Controller
{
    public function index(Workspace $workspace, Channel $channel, Request $request): JsonResponse
    {
        $this->authorize('post', $channel);

        $term = trim((string) $request->string('q'));

        $people = $channel->users()
            // You are not offered to yourself: mentioning yourself notifies
            // nobody, so the server would discard it anyway.
            ->whereKeyNot($request->user()->id)
            ->when($term !== '', fn ($query) => $query->where(
                fn ($match) => $match
                    ->where('users.name', 'ilike', '%'.$term.'%')
                    ->orWhere('users.username', 'ilike', '%'.$term.'%'),
            ))
            ->orderBy('users.name')
            // A picker, not an export. The composer shows a handful at a time.
            ->limit(20)
            ->get();

        return response()->json([
            'people' => $people
                ->map(fn (User $user): array => (new UserResource($user))->resolve($request))
                ->all(),
        ]);
    }
}
