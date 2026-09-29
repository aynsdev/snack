<?php

namespace App\Http\Requests\Hr;

use App\Models\TimeEntry;
use Illuminate\Foundation\Http\FormRequest;

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
}
