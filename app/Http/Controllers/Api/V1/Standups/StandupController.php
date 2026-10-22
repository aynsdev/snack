<?php

namespace App\Http\Controllers\Api\V1\Standups;

use App\Actions\Standups\CreateStandup;
use App\Actions\Standups\DeleteStandup;
use App\Actions\Standups\UpdateStandup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Standups\StoreStandupRequest;
use App\Http\Requests\Standups\UpdateStandupRequest;
use App\Http\Resources\StandupResource;
use App\Models\Channel;
use App\Models\Standup;
use App\Models\Workspace;
use App\Services\StandupBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The standups a workspace runs — the mirror of
 * {@see \App\Http\Controllers\Standups\StandupController}. Admin-gated: see
 * App\Policies\StandupPolicy. Answering one is the web's own JSON endpoint,
 * StandupResponseController, routed straight through.
 */
class StandupController extends Controller
{
    public function __construct(private readonly StandupBot $bot) {}

    /**
     * Every standup with its latest run, plus what the form needs: the
     * channels it may point at, every valid timezone, the defaults, and the
     * bot account the questions come from.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('viewAny', [Standup::class, $workspace]);

        $standups = $workspace->standups()
            ->with([
                'channel',
                'runs' => fn ($query) => $query
                    ->latest('scheduled_for')
                    ->limit(1)
                    ->withCount([
                        'responses',
                        'responses as answered_count' => fn ($answered) => $answered->whereNotNull('submitted_at'),
                    ]),
            ])
            ->orderBy('name')
            ->get();

        $this->attachParticipantCounts($workspace, $standups);

        $bot = $this->bot->existingAccountFor($workspace);

        return response()->json([
            'data' => [
                'standups' => StandupResource::collection($standups)->resolve($request),
                'channels' => $workspace->channels()
                    ->notArchived()
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug', 'type'])
                    ->map(fn (Channel $channel): array => [
                        'id' => $channel->id,
                        'name' => $channel->name,
                        'slug' => $channel->slug,
                        'type' => $channel->type->value,
                    ])
                    ->all(),
                'timezones' => timezone_identifiers_list(),
                'defaults' => [
                    'questions' => Standup::DEFAULT_QUESTIONS,
                    'days_of_week' => Standup::DEFAULT_DAYS,
                    'ask_at' => '09:30',
                    'timezone' => $request->user()->timezone,
                    'reminder_after_minutes' => (int) config('snack.standups.reminder_after_minutes', 90),
                    'closes_after_minutes' => (int) config('snack.standups.closes_after_minutes', 240),
                ],
                // Read without creating: looking must not install a bot.
                'bot' => $bot === null
                    ? ['name' => 'Standup', 'username' => 'standup', 'exists' => false]
                    : ['name' => $bot->name, 'username' => $bot->username, 'exists' => true],
                'max_questions' => (int) config('snack.standups.max_questions', 10),
            ],
        ]);
    }

    public function store(Workspace $workspace, StoreStandupRequest $request, CreateStandup $createStandup): JsonResponse
    {
        $standup = $createStandup->handle(
            workspace: $workspace,
            channel: $this->channel($workspace, $request->integer('channel_id')),
            creator: $request->user(),
            attributes: $this->attributes($request),
        );

        return (new StandupResource($standup->load('channel')))->response()->setStatusCode(201);
    }

    public function update(
        Workspace $workspace,
        Standup $standup,
        UpdateStandupRequest $request,
        UpdateStandup $updateStandup,
    ): StandupResource {
        $channel = $this->channel($workspace, $request->integer('channel_id'));

        $updateStandup->handle(
            standup: $standup,
            actor: $request->user(),
            attributes: $this->attributes($request),
            channel: $channel->id === $standup->channel_id ? null : $channel,
        );

        return new StandupResource($standup->refresh()->load('channel'));
    }

    public function destroy(
        Workspace $workspace,
        Standup $standup,
        Request $request,
        DeleteStandup $deleteStandup,
    ): Response {
        $this->authorize('delete', $standup);

        $deleteStandup->handle($standup, $request->user());

        return response()->noContent();
    }

    /**
     * @return array{name: string, questions: array<int, string>, days_of_week: array<int, int>, ask_at: string, timezone: string, reminder_after_minutes: int|null, closes_after_minutes: int, is_active: bool}
     */
    private function attributes(StoreStandupRequest|UpdateStandupRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'questions' => $request->questions(),
            'days_of_week' => $request->weekdays(),
            'ask_at' => $request->string('ask_at')->toString(),
            'timezone' => $request->string('timezone')->toString(),
            'reminder_after_minutes' => $request->reminderAfterMinutes(),
            'closes_after_minutes' => $request->integer('closes_after_minutes'),
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    private function channel(Workspace $workspace, int $channelId): Channel
    {
        return $workspace->channels()->whereKey($channelId)->firstOrFail();
    }

    /**
     * How many live, human channel members each standup asks — one query for
     * the page, excluding bots and the suspended as Standup::participants() does.
     *
     * @param  Collection<int, Standup>  $standups
     */
    private function attachParticipantCounts(Workspace $workspace, Collection $standups): void
    {
        $channelIds = $standups->pluck('channel_id')->unique()->values();

        if ($channelIds->isEmpty()) {
            return;
        }

        $counts = DB::table('channel_members')
            ->join('users', 'users.id', '=', 'channel_members.user_id')
            ->join('workspace_members', function ($join) use ($workspace): void {
                $join->on('workspace_members.user_id', '=', 'channel_members.user_id')
                    ->where('workspace_members.workspace_id', '=', $workspace->id);
            })
            ->whereIn('channel_members.channel_id', $channelIds)
            ->where('users.is_bot', false)
            ->where('workspace_members.status', 'active')
            ->groupBy('channel_members.channel_id')
            ->selectRaw('channel_members.channel_id as channel_id, count(*) as total')
            ->pluck('total', 'channel_id');

        $standups->each(function (Standup $standup) use ($counts): void {
            $standup->participant_count = (int) ($counts[$standup->channel_id] ?? 0);
        });
    }
}
