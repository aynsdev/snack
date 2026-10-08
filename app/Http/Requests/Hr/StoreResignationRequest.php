<?php

namespace App\Http\Requests\Hr;

use App\Models\Resignation;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;

class StoreResignationRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->can('create', [Resignation::class, $context->workspace()]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:5000'],
            'last_day_on' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
