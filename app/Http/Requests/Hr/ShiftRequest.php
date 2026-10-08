<?php

namespace App\Http\Requests\Hr;

use App\Models\Shift;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating or editing a shift — the working hours themselves, not a request
 * to change somebody's; that is {@see StoreShiftRequestRequest}.
 */
class ShiftRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        $shift = $this->route('shift');

        return $shift instanceof Shift
            ? ($this->user()?->can('update', $shift) ?? false)
            : ($this->user()?->can('create', [Shift::class, $context->workspace()]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'is_default' => ['boolean'],
        ];
    }

    /**
     * @return array{name: string, starts_at: string, ends_at: string, grace_minutes: int, days: array<int, int>, is_default: bool}
     */
    public function shift(): array
    {
        $days = array_map('intval', (array) $this->validated('days'));
        sort($days);

        return [
            'name' => (string) $this->validated('name'),
            'starts_at' => (string) $this->validated('starts_at'),
            'ends_at' => (string) $this->validated('ends_at'),
            'grace_minutes' => (int) $this->validated('grace_minutes'),
            'days' => $days,
            'is_default' => $this->boolean('is_default'),
        ];
    }
}
