<?php

namespace App\Console\Commands;

use App\Actions\Standups\AskStandup;
use App\Actions\Standups\CloseStandup;
use App\Actions\Standups\RemindStandup;
use App\Models\Standup;
use App\Models\StandupRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Drives every standup in the installation: asks, chases, and writes up.
 *
 * # Why one command for three jobs
 *
 * They are three moments in the life of one run, they read the same rows, and
 * they have to happen in this order — a run cannot be reminded before it is
 * asked or closed before it is reminded. Three commands would be three
 * schedules that can interleave, and the interleaving that matters is the one
 * where a close beats an ask by a second and posts a summary of a standup
 * nobody was asked.
 *
 * # Why every minute
 *
 * Because 09:30 means 09:30. The digest can run hourly since it only promises a
 * day; a standup is a meeting, and being asked at 09:00 for a 09:30 standup is
 * the same failure as being asked at 10:00. The work when nothing is due is one
 * indexed query over the active standups and one over the open runs, both of
 * which are normally empty sets.
 *
 * Nothing here decides whether a run already happened — that is the unique index
 * on `standup_runs`, which is the only thing that can settle it when two workers
 * wake on the same minute. See {@see AskStandup}.
 */
class RunStandups extends Command
{
    /*
     * `--force` asks; `--close` writes up. Two flags rather than one that did
     * both, because one that did both is useless for the thing it exists for:
     * asking a standup out of schedule to try it produced a run that was closed
     * again in the same invocation, so nobody could answer the prompt they had
     * just been sent.
     */
    protected $signature = 'snack:run-standups
                            {--standup= : Only this standup id, for checking one schedule}
                            {--force : Ask now regardless of the schedule}
                            {--close : Write up and post every open run, whatever its window says}';

    protected $description = 'Ask, chase and write up the standups that are due';

    public function handle(
        AskStandup $ask,
        RemindStandup $remind,
        CloseStandup $close,
    ): int {
        $now = CarbonImmutable::now();

        $asked = $this->openDueRuns($ask, $now);

        /*
         * Reminders and closes are swept over the *open runs*, not over the
         * standups — a run outlives the schedule that created it. Reading the
         * schedule instead would mean an admin who paused a standup at eleven
         * o'clock left this morning's run open forever, with everybody who
         * answered it never appearing in the channel.
         */
        $reminded = $this->remind($remind, $now);
        $closed = $this->close($close, $now);

        $this->info("Asked {$asked}, reminded {$reminded}, closed {$closed}.");

        return self::SUCCESS;
    }

    /**
     * Open the runs that have come due.
     */
    private function openDueRuns(AskStandup $ask, CarbonImmutable $now): int
    {
        $opened = 0;

        $this->standups()->each(function (Standup $standup) use ($ask, $now, &$opened): void {
            $date = $this->option('force')
                // A forced ask is dated by the standup's own clock, not the
                // server's: `--force` is for trying a standup out, and it should
                // land on the day the team would call today.
                ? $standup->localNow($now)->startOfDay()
                : $standup->dueDate($now);

            if ($date === null) {
                return;
            }

            $run = $ask->handle($standup, $date);

            if ($run->wasRecentlyCreated) {
                $opened++;
            }
        });

        return $opened;
    }

    private function remind(RemindStandup $remind, CarbonImmutable $now): int
    {
        $nudged = 0;

        $this->openRuns()
            ->filter(fn (StandupRun $run): bool => $run->asked_at !== null
                && $run->reminded_at === null
                && $run->remindsAt()?->lessThanOrEqualTo($now) === true)
            ->each(function (StandupRun $run) use ($remind, &$nudged): void {
                $nudged += $remind->handle($run);
            });

        return $nudged;
    }

    private function close(CloseStandup $close, CarbonImmutable $now): int
    {
        $closed = 0;

        $this->openRuns()
            ->filter(fn (StandupRun $run): bool => $this->option('close')
                || $run->closesAt()->lessThanOrEqualTo($now))
            ->each(function (StandupRun $run) use ($close, &$closed): void {
                $close->handle($run);
                $closed++;
            });

        return $closed;
    }

    /**
     * The standups this invocation is allowed to touch.
     *
     * `channel` and `workspace` are eager-loaded because every one of the three
     * passes reads both — the prompt names the channel, the summary is posted to
     * it, and the bot is resolved per workspace. Left lazy this was two queries
     * per standup per minute.
     *
     * @return Collection<int, Standup>
     */
    private function standups(): Collection
    {
        return Standup::query()
            ->active()
            ->with(['channel', 'workspace'])
            ->when(
                $this->option('standup'),
                fn ($query) => $query->whereKey((int) $this->option('standup')),
            )
            ->get();
    }

    /**
     * Runs still taking answers.
     *
     * Read fresh for each pass rather than once: the ask above may have just
     * opened some of these, and a run that closed itself the moment its last
     * answer arrived must not be closed a second time by the sweep in the same
     * invocation.
     *
     * @return Collection<int, StandupRun>
     */
    private function openRuns(): Collection
    {
        return StandupRun::query()
            ->open()
            ->with(['standup.channel', 'standup.workspace'])
            ->when(
                $this->option('standup'),
                fn ($query) => $query->where('standup_id', (int) $this->option('standup')),
            )
            ->get();
    }
}
