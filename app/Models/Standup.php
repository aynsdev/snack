<?php

// CarbonImmutable rather than Carbon: see the note in App\Models\Huddle.

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StandupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A recurring set of questions the standup bot asks a channel's members.
 *
 * Its roster is the channel's membership, optionally narrowed to a chosen few
 * by `participant_ids` — see {@see participants()} — and its schedule is a set of weekdays, a
 * local time, and the timezone that time is read in.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property int|null $created_by
 * @property string $name
 * @property array<int, string> $questions
 * @property array<int, int> $days_of_week
 * @property string $ask_at
 * @property string $timezone
 * @property int|null $reminder_after_minutes
 * @property int $closes_after_minutes
 * @property bool $is_active
 * @property array<int, int>|null $participant_ids Null asks the whole channel;
 *                                                 a list asks only those people
 *                                                 who are also still in it.
 * @property int|null $participant_count Attached by the admin screen — the
 *                                       channel's live human members, counted once for the whole page
 *                                       rather than per row. Null anywhere it was not asked for.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Channel $channel
 */
#[Fillable([
    'workspace_id', 'channel_id', 'created_by', 'name', 'questions', 'days_of_week',
    'ask_at', 'timezone', 'reminder_after_minutes', 'closes_after_minutes', 'is_active',
    'participant_ids',
])]
class Standup extends Model
{
    /** @use HasFactory<StandupFactory> */
    use HasFactory;

    /**
     * The questions a standup is created with when its author does not write
     * their own. The three every standup has ever asked, in the order that makes
     * the summary read as a sentence.
     *
     * @var array<int, string>
     */
    public const DEFAULT_QUESTIONS = [
        'What did you do since the last standup?',
        'What are you working on today?',
        'Is anything blocking you?',
    ];

    /** Monday to Friday, as ISO weekday numbers. */
    public const DEFAULT_DAYS = [1, 2, 3, 4, 5];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'days_of_week' => 'array',
            'reminder_after_minutes' => 'integer',
            'closes_after_minutes' => 'integer',
            'is_active' => 'boolean',
            'participant_ids' => 'array',
            /*
             * `ask_at` is deliberately uncast. It is a bare TIME — 09:30 with no
             * date — and casting it to a datetime would attach today's date and
             * the application timezone to it, which is exactly the pair of
             * assumptions this column exists to avoid. It is read through
             * {@see askMomentOn()}, which applies the standup's own timezone to
             * a date the caller names.
             */
        ];
    }

    // -- Relationships -------------------------------------------------------

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<StandupRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(StandupRun::class);
    }

    // -- Scopes --------------------------------------------------------------

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    // -- The roster ----------------------------------------------------------

    /**
     * Who gets asked: the channel's members, minus bots, minus anyone whose
     * workspace membership is no longer active — and, when the standup has
     * been narrowed to a chosen few, minus everyone not on that list.
     *
     * The selection only ever filters the channel; it never adds to it. So a
     * chosen person who leaves the channel stops being asked without anybody
     * having to edit the standup.
     *
     * The suspension check is not belt and braces. A suspended account is
     * treated as a non-member everywhere else in Snack, and DMing one every
     * morning would be the one place the workspace kept talking to somebody it
     * had shut out.
     *
     * @return Collection<int, User>
     */
    public function participants(): Collection
    {
        return User::query()
            ->where('is_bot', false)
            ->whereIn(
                'id',
                ChannelMember::query()
                    ->where('channel_id', $this->channel_id)
                    ->select('user_id'),
            )
            ->whereIn(
                'id',
                WorkspaceMember::query()
                    ->where('workspace_id', $this->workspace_id)
                    ->active()
                    ->select('user_id'),
            )
            ->when(
                $this->restrictsParticipants(),
                fn (Builder $query) => $query->whereIn('id', $this->selectedParticipantIds()),
            )
            ->orderBy('name')
            ->get();
    }

    /**
     * Whether this standup asks a chosen few rather than the whole channel.
     */
    public function restrictsParticipants(): bool
    {
        return $this->participant_ids !== null;
    }

    /**
     * The chosen user ids, as clean integers. Empty when the whole channel is
     * asked — check {@see restrictsParticipants()} to tell that apart from a
     * selection of nobody.
     *
     * @return array<int, int>
     */
    public function selectedParticipantIds(): array
    {
        return collect($this->participant_ids ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Attach `participant_count` to each standup with one query for the whole
     * collection: the live human members of every channel involved, filtered
     * per standup by its selection exactly as {@see participants()} filters.
     *
     * @param  Collection<int, self>  $standups
     */
    public static function attachParticipantCounts(int $workspaceId, Collection $standups): void
    {
        $channelIds = $standups->pluck('channel_id')->unique()->values();

        if ($channelIds->isEmpty()) {
            return;
        }

        $membersByChannel = self::liveMemberIdsByChannel($workspaceId, $channelIds->all());

        $standups->each(function (self $standup) use ($membersByChannel): void {
            $members = $membersByChannel[$standup->channel_id] ?? [];

            $standup->participant_count = $standup->restrictsParticipants()
                ? count(array_intersect($members, $standup->selectedParticipantIds()))
                : count($members);
        });
    }

    /**
     * The live, human member ids of each channel — the same exclusions the
     * roster makes — keyed by channel id.
     *
     * @param  array<int, int>  $channelIds
     * @return array<int, array<int, int>>
     */
    public static function liveMemberIdsByChannel(int $workspaceId, array $channelIds): array
    {
        if ($channelIds === []) {
            return [];
        }

        return ChannelMember::query()
            ->join('users', 'users.id', '=', 'channel_members.user_id')
            ->join('workspace_members', function ($join) use ($workspaceId): void {
                $join->on('workspace_members.user_id', '=', 'channel_members.user_id')
                    ->where('workspace_members.workspace_id', '=', $workspaceId);
            })
            ->whereIn('channel_members.channel_id', $channelIds)
            ->where('users.is_bot', false)
            ->where('workspace_members.status', 'active')
            ->get(['channel_members.channel_id', 'channel_members.user_id'])
            ->groupBy('channel_id')
            ->map(fn ($rows): array => $rows->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->values()->all())
            ->all();
    }

    // -- The schedule --------------------------------------------------------

    /**
     * The questions, as a clean list.
     *
     * Trimmed and re-indexed here rather than trusted as stored, because this is
     * jsonb and its keys survive an edit: removing the middle question of three
     * leaves indices 0 and 2, and a prompt numbered "1." then "3." is a bug
     * nobody would think to look for in a JSON column.
     *
     * @return array<int, string>
     */
    public function questionList(): array
    {
        return collect($this->questions)
            ->map(fn (string $question): string => trim($question))
            ->filter(fn (string $question): bool => $question !== '')
            ->values()
            ->all();
    }

    /**
     * The ISO weekday numbers this standup runs on, sorted and deduplicated.
     *
     * @return array<int, int>
     */
    public function weekdays(): array
    {
        return collect($this->days_of_week)
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function runsOnWeekday(int $isoWeekday): bool
    {
        return in_array($isoWeekday, $this->weekdays(), true);
    }

    /**
     * `now` in the standup's own timezone.
     */
    public function localNow(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone($this->timezone);
    }

    /**
     * The instant this standup is asked on a given local date.
     */
    public function askMomentOn(CarbonImmutable $localDate): CarbonImmutable
    {
        return $localDate
            ->setTimezone($this->timezone)
            ->startOfDay()
            ->setTimeFromTimeString($this->ask_at);
    }

    /**
     * The local date whose standup is due right now, or null if none is.
     *
     * # Why this looks backwards as well as at today
     *
     * The scheduler wakes every minute, but a minute can be missed — a worker
     * restart, a slow run, a machine that was asleep — and a standup that is
     * asked only if the clock is examined during the exact minute it was due is
     * one that silently does not happen. So the question asked here is not "is
     * it 09:30 right now" but "is there an ask time in the recent past that has
     * not been honoured", bounded by `$graceMinutes`.
     *
     * The bound matters as much as the lookback: without it, enabling a standup
     * at four in the afternoon would immediately fire that morning's, and a
     * deployment that was down overnight would wake up and DM everybody about a
     * standup whose day is over. Whether the run *already happened* is not
     * decided here at all — that is the unique index on `standup_runs`.
     */
    public function dueDate(?CarbonImmutable $now = null, ?int $graceMinutes = null): ?CarbonImmutable
    {
        if (! $this->is_active || $this->questionList() === [] || $this->weekdays() === []) {
            return null;
        }

        $grace = $graceMinutes ?? (int) config('snack.standups.catch_up_minutes', 60);
        $local = $this->localNow($now);

        $scheduled = $this->askMomentOn($local);

        // Before today's ask time, the only candidate is yesterday's — which is
        // still live if the grace window has not closed on it.
        if ($scheduled->greaterThan($local)) {
            $scheduled = $this->askMomentOn($local->subDay());
        }

        if (! $this->runsOnWeekday($scheduled->dayOfWeekIso)) {
            return null;
        }

        return $scheduled->diffInMinutes($local) <= $grace
            ? $scheduled->startOfDay()
            : null;
    }

    /**
     * How the schedule reads in one line: "Weekdays at 09:30 (Europe/London)".
     */
    public function scheduleSummary(): string
    {
        $days = $this->weekdays();

        $label = match (true) {
            $days === [1, 2, 3, 4, 5] => 'Weekdays',
            $days === [1, 2, 3, 4, 5, 6, 7] => 'Every day',
            default => collect($days)
                ->map(fn (int $day): string => self::weekdayName($day))
                ->join(', ', ' and '),
        };

        return "{$label} at {$this->askAtLabel()} ({$this->timezone})";
    }

    /**
     * The ask time as "09:30" — the TIME column arrives from Postgres with
     * seconds on it, which nobody set and nobody wants to read.
     */
    public function askAtLabel(): string
    {
        return substr($this->ask_at, 0, 5);
    }

    public static function weekdayName(int $isoWeekday): string
    {
        return match ($isoWeekday) {
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            default => 'Sunday',
        };
    }
}
