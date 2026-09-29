<?php

namespace App\Http\Requests\Hr;

use App\Models\TimeEntry;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A clock-in or clock-out, with wherever the browser said the person was.
 */
class ClockRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('clock', [TimeEntry::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{latitude: float|null, longitude: float|null, accuracy: float|null}
     */
    public function location(): array
    {
        return [
            'latitude' => $this->filled('latitude') ? (float) $this->input('latitude') : null,
            'longitude' => $this->filled('longitude') ? (float) $this->input('longitude') : null,
            'accuracy' => $this->filled('accuracy') ? (float) $this->input('accuracy') : null,
        ];
    }
}
