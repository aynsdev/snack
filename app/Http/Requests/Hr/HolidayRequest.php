<?php

namespace App\Http\Requests\Hr;

use App\Models\Holiday;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;

class HolidayRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        $holiday = $this->route('holiday');

        return $holiday instanceof Holiday
            ? ($this->user()?->can('update', $holiday) ?? false)
            : ($this->user()?->can('create', [Holiday::class, $context->workspace()]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'date' => ['required', 'date'],
            'is_recurring' => ['boolean'],
        ];
    }
}
