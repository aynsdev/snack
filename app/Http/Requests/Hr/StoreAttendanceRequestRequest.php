<?php

namespace App\Http\Requests\Hr;

use App\Models\AttendanceRequest;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreAttendanceRequestRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [AttendanceRequest::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        $employeeId = $this->user()?->employeeIn($context->workspace())->id ?? 0;

        return [
            // Only one of your own, closed entries can be corrected.
            'time_entry_id' => [
                'nullable', 'integer',
                Rule::exists('time_entries', 'id')
                    ->where('employee_id', $employeeId)
                    ->whereNotNull('clock_out_at'),
            ],
            'clock_in_at' => ['required', 'date', 'before:now'],
            'clock_out_at' => ['required', 'date', 'after:clock_in_at', 'before_or_equal:now'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * The requested times in the app's timezone — see
     * {@see UpdateTimeEntryRequest::times()} for why.
     *
     * @return array{clock_in_at: Carbon, clock_out_at: Carbon}
     */
    public function times(): array
    {
        $timezone = config('app.timezone');

        return [
            'clock_in_at' => Carbon::parse($this->validated('clock_in_at'))->setTimezone($timezone),
            'clock_out_at' => Carbon::parse($this->validated('clock_out_at'))->setTimezone($timezone),
        ];
    }
}
