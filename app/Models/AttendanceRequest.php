<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Services\Hr\Punctuality;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Asking HR to fix a shift on the timesheet: correct one that was clocked
 * wrong, or add one that was never clocked at all.
 *
 * @property int|null $time_entry_id
 * @property Carbon $clock_in_at
 * @property Carbon $clock_out_at
 * @property-read TimeEntry|null $timeEntry
 */
#[Fillable([
    'workspace_id', 'employee_id', 'time_entry_id', 'clock_in_at', 'clock_out_at', 'reason',
    'status', 'reviewer_id', 'reviewed_at', 'review_note',
])]
class AttendanceRequest extends HrRequest
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TimeEntry, $this>
     */
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    public function isCorrection(): bool
    {
        return $this->time_entry_id !== null;
    }

    public function kind(): string
    {
        return 'attendance correction';
    }

    public function summary(): string
    {
        $span = $this->clock_in_at->format('M j, H:i').' – '.(
            $this->clock_out_at->isSameDay($this->clock_in_at)
                ? $this->clock_out_at->format('H:i')
                : $this->clock_out_at->format('M j, H:i')
        );

        return ($this->isCorrection() ? 'Correct a shift to ' : 'Add a missing shift, ').$span;
    }

    /**
     * Writes the requested times onto the timesheet — over the entry being
     * corrected, or as a new one — and re-measures it against the shift.
     */
    public function applyApproval(): void
    {
        $times = ['clock_in_at' => $this->clock_in_at, 'clock_out_at' => $this->clock_out_at];

        $entry = $this->timeEntry;

        if ($entry === null) {
            $entry = $this->employee->timeEntries()->create([
                'workspace_id' => $this->workspace_id,
                ...$times,
                'note' => 'Added on request',
            ]);

            $this->update(['time_entry_id' => $entry->id]);
        } else {
            $entry->update($times);
        }

        app(Punctuality::class)->measure($entry);
    }

    public function auditAction(bool $approved): AuditAction
    {
        return $approved ? AuditAction::AttendanceRequestApproved : AuditAction::AttendanceRequestRejected;
    }
}
