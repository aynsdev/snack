<?php

namespace App\Services\Hr;

use App\Models\TimeEntry;

/**
 * Measures a time entry against the shift its person was on that day: how late
 * they clocked in, how early they left, how long they stayed past the end.
 *
 * Lateness and leaving early only count beyond the shift's grace minutes, and
 * once they do they count in full — ten minutes late with five minutes' grace
 * is ten minutes late. A day the shift is not worked, or a person with no
 * shift at all, measures nothing: the minutes stay null rather than zero, so
 * "on time" and "not scheduled" are not confused.
 */
class Punctuality
{
    public function __construct(private readonly ShiftResolver $shifts) {}

    public function measure(TimeEntry $entry): TimeEntry
    {
        $entry->loadMissing('employee.workspace');

        $clockIn = $entry->clock_in_at->copy()->setTimezone(config('app.timezone'));
        $shift = $this->shifts->shiftFor($entry->employee, $clockIn);

        if ($shift === null || ! $shift->worksOn($clockIn)) {
            $entry->update([
                'shift_id' => $shift?->id,
                'late_minutes' => null,
                'early_out_minutes' => null,
                'overtime_minutes' => null,
            ]);

            return $entry;
        }

        [$start, $end] = $shift->windowOn($clockIn);
        $grace = $shift->grace_minutes;

        $late = max(0, (int) $start->diffInMinutes($entry->clock_in_at, false));

        $earlyOut = null;
        $overtime = null;

        if ($entry->clock_out_at !== null) {
            $earlyOut = max(0, (int) $entry->clock_out_at->diffInMinutes($end, false));
            $overtime = max(0, (int) $end->diffInMinutes($entry->clock_out_at, false));
        }

        $entry->update([
            'shift_id' => $shift->id,
            'late_minutes' => $late > $grace ? $late : 0,
            'early_out_minutes' => $earlyOut === null ? null : ($earlyOut > $grace ? $earlyOut : 0),
            'overtime_minutes' => $overtime,
        ]);

        return $entry;
    }
}
