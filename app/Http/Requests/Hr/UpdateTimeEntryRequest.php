<?php

namespace App\Http\Requests\Hr;

use App\Models\TimeEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class UpdateTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var TimeEntry $timeEntry */
        $timeEntry = $this->route('timeEntry');

        return $this->user()?->can('update', $timeEntry) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clock_in_at' => ['required', 'date'],
            'clock_out_at' => ['nullable', 'date', 'after:clock_in_at'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The validated times moved into the app's timezone. The browser sends
     * UTC instants, and the datetime cast stores a Carbon's wall-clock time
     * without converting it, which would shift every corrected shift.
     *
     * @return array{clock_in_at: Carbon, clock_out_at: Carbon|null, note: string|null}
     */
    public function times(): array
    {
        $timezone = config('app.timezone');

        return [
            'clock_in_at' => Carbon::parse($this->validated('clock_in_at'))->setTimezone($timezone),
            'clock_out_at' => $this->filled('clock_out_at')
                ? Carbon::parse($this->validated('clock_out_at'))->setTimezone($timezone)
                : null,
            'note' => $this->validated('note'),
        ];
    }
}
