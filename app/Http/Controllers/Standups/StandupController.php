<?php

namespace App\Http\Controllers\Standups;

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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The standups a workspace runs, and the form that configures them.
 *
 * Admin-gated — see {@see App\Policies\StandupPolicy} for why scheduling
 * recurring direct messages to a whole channel is a right that sits with the
 * people who already decide what the workspace does to its members.
 *
 * Ordinary Inertia visits throughout, unlike the message and card endpoints:
 * every action here changes the page as a whole, and there is nothing live on
 * it to merge.
 */
class StandupController extends Controller
{
    public function __construct(private readonly StandupBot $bot) {}

    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('viewAny', [Standup::class, $workspace]);

        $standups = $workspace->standups()
            ->with([
                'channel',
                /*
                 * The most recent run only, with its two tallies. A standup's
                 * whole history is not what this screen is for — "did this
                 * morning's go out, and how many people answered" is — and
                 * loading it would grow the page with every day the feature has
                 * been switched on.
                 */
                'runs' => fn ($query) => $query
                    ->latest('scheduled_for')
                    ->limit(1)
                    ->withCount([
                        'responses',
                        'responses as answered_count' => fn ($answered) => $answered
                            ->whereNotNull('submitted_at'),
                    ]),
            ])
            ->orderBy('name')
            ->get();

        $this->attachParticipantCounts($workspace, $standups);

        return Inertia::render('workspace/standups', [
            'standups' => StandupResource::collection($standups)->resolve($request),

            /*
             * The channels a standup may be pointed at: this workspace's live
             * ones. Private channels are included — a squad standup usually
             * belongs in the squad's private channel — and the admin is being
             * shown the names, not the contents.
             */
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

            /*
             * Every timezone the server recognises, so the picker and the
             * `timezone` validation rule can never disagree about what is
             * valid. It is a static list of a few hundred strings and it
             * compresses to nothing.
             */
            'timezones' => timezone_identifiers_list(),

            // What the form starts with, kept server-side so the defaults an
            // admin sees are the ones the action would have applied anyway.
            'defaults' => [
                'questions' => Standup::DEFAULT_QUESTIONS,
                'days_of_week' => Standup::DEFAULT_DAYS,
                'ask_at' => '09:30',
                'timezone' => $request->user()->timezone,
                'reminder_after_minutes' => (int) config('snack.standups.reminder_after_minutes', 90),
                'closes_after_minutes' => (int) config('snack.standups.closes_after_minutes', 240),
            ],

            /*
             * The account the questions arrive from, so the screen can say who
             * that is. Read without creating: an admin opening this page must
             * not bring a bot into existence as a side effect of looking.
             */
            'bot' => $this->botSummary($workspace),

            'max_questions' => (int) config('snack.standups.max_questions', 10),
        ]);
    }

    public function store(
        Workspace $workspace,
        StoreStandupRequest $request,
        CreateStandup $createStandup,
    ): RedirectResponse {
        $channel = $this->channel($workspace, $request->integer('channel_id'));

        $standup = $createStandup->handle(
            workspace: $workspace,
            channel: $channel,
            creator: $request->user(),
            attributes: $this->attributes($request),
        );

        return to_route('standups.index', ['workspace' => $workspace->slug])
            ->with('status', "{$standup->name} will run {$standup->scheduleSummary()}.");
    }

    public function update(
        Workspace $workspace,
        Standup $standup,
        UpdateStandupRequest $request,
        UpdateStandup $updateStandup,
    ): RedirectResponse {
        $channel = $this->channel($workspace, $request->integer('channel_id'));

        $updateStandup->handle(
            standup: $standup,
            actor: $request->user(),
            attributes: $this->attributes($request),
            // Only handed over when it actually moves, so an ordinary edit does
            // not make the bot re-check a channel membership it already has.
            channel: $channel->id === $standup->channel_id ? null : $channel,
        );

        return to_route('standups.index', ['workspace' => $workspace->slug]);
    }

    public function destroy(
        Workspace $workspace,
        Standup $standup,
        Request $request,
        DeleteStandup $deleteStandup,
    ): RedirectResponse {
        $this->authorize('delete', $standup);

        $deleteStandup->handle($standup, $request->user());

        return to_route('standups.index', ['workspace' => $workspace->slug]);
    }

    /**
     * The validated schedule, in the shape the actions take.
     *
     * The array shape is spelled out rather than left as `array<string, mixed>`:
     * it is the contract between this controller and two actions, and a mixed
     * bag would let a renamed key reach them as a silently missing default.
     *
     * @return array{
     *     name: string,
     *     questions: array<int, string>,
     *     days_of_week: array<int, int>,
     *     ask_at: string,
     *     timezone: string,
     *     reminder_after_minutes: int|null,
     *     closes_after_minutes: int,
     *     is_active: bool,
     * }
     */
    private function attributes(StoreStandupRequest|UpdateStandupRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            // Read through the request's own accessors, which narrow what the
            // rules have already established — see ValidatesStandupSchedule.
            'questions' => $request->questions(),
            'days_of_week' => $request->weekdays(),
            'ask_at' => $request->string('ask_at')->toString(),
            'timezone' => $request->string('timezone')->toString(),
            'reminder_after_minutes' => $request->reminderAfterMinutes(),
            'closes_after_minutes' => $request->integer('closes_after_minutes'),
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    /**
     * Resolve the channel through the workspace, never straight from the id.
     *
     * The request rule has already established that the id belongs here, so this
     * is belt and braces — but it is the cheap kind: it makes the model the
     * action receives one that came out of the tenant's own relation.
     */
    private function channel(Workspace $workspace, int $channelId): Channel
    {
        return $workspace->channels()->whereKey($channelId)->firstOrFail();
    }

    /**
     * How many people each standup actually messages.
     *
     * One query for the whole page rather than a roster query per standup: this
     * is a count of the channel's live human members, and the same channel may
     * back more than one standup.
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
            // The same two exclusions the roster itself makes — see
            // Standup::participants(). A count that included the bot and the
            // suspended would be a number the morning does not match.
            ->where('users.is_bot', false)
            ->where('workspace_members.status', 'active')
            ->groupBy('channel_members.channel_id')
            ->selectRaw('channel_members.channel_id as channel_id, count(*) as total')
            ->pluck('total', 'channel_id');

        $standups->each(function (Standup $standup) use ($counts): void {
            $standup->participant_count = (int) ($counts[$standup->channel_id] ?? 0);
        });
    }

    /**
     * @return array{name: string, username: string, exists: bool}
     */
    private function botSummary(Workspace $workspace): array
    {
        $bot = $this->bot->existingAccountFor($workspace);

        // Null until the first standup is created — the account is brought into
        // being by installing one, not by looking at this screen. The screen
        // still has to name it, so it falls back to what it will be called.
        if ($bot === null) {
            return ['name' => 'Standup', 'username' => 'standup', 'exists' => false];
        }

        return ['name' => $bot->name, 'username' => $bot->username, 'exists' => true];
    }
}
