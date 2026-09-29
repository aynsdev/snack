<?php

namespace App\Services\Hr;

use App\Models\Holiday;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Answers "how many working days is this?" for a workspace.
 *
 * Weekends and the workspace's holidays — including recurring ones, projected
 * into each year the range touches — are not working days, so booking a week
 * that contains a public holiday costs four days of leave, not five.
 */
class LeaveCalendar
{
    public function workingDaysBetween(Workspace $workspace, CarbonInterface $from, CarbonInterface $to): float
    {
        $holidays = $this->holidayDatesBetween($workspace, $from, $to);

        $days = 0;

        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            if ($day->isWeekend() || $holidays->contains($day->toDateString())) {
                continue;
            }

            $days++;
        }

        return (float) $days;
    }

    /**
     * Every holiday date inside the range, as Y-m-d strings.
     *
     * @return Collection<int, string>
     */
    public function holidayDatesBetween(Workspace $workspace, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $years = range($from->year, $to->year);

        return $workspace->holidays()
            ->fallingBetween($from, $to)
            ->get()
            ->flatMap(fn (Holiday $holiday): array => array_filter(array_map(
                fn (int $year): ?CarbonInterface => $holiday->occurrenceIn($year),
                $years,
            )))
            ->filter(fn (CarbonInterface $date): bool => $date->betweenIncluded($from->copy()->startOfDay(), $to->copy()->startOfDay()))
            ->map(fn (CarbonInterface $date): string => $date->toDateString())
            ->unique()
            ->values();
    }
}
