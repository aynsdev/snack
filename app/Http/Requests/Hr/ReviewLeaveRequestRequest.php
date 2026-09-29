<?php

namespace App\Http\Requests\Hr;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

class ReviewLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var LeaveRequest $leaveRequest */
        $leaveRequest = $this->route('leaveRequest');

        return $this->user()?->can('review', $leaveRequest) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
