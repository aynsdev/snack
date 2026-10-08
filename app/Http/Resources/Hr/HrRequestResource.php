<?php

namespace App\Http\Resources\Hr;

use App\Models\AttendanceRequest;
use App\Models\HrRequest;
use App\Models\ShiftRequest;
use App\Models\WorkTypeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One shift, work-type, or attendance request, told apart by `type`.
 *
 * @mixin HrRequest
 */
class HrRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'type' => match ($this->resource::class) {
                ShiftRequest::class => 'shift',
                WorkTypeRequest::class => 'work_type',
                AttendanceRequest::class => 'attendance',
                default => throw new \LogicException('Unknown HR request: '.$this->resource::class),
            },
            'kind' => $this->kind(),
            'summary' => $this->summary(),
            'employee' => [
                'id' => $this->employee->id,
                'name' => $this->employee->user->name,
                'initials' => $this->employee->user->initials(),
                'avatar_url' => $this->employee->user->avatarUrl(),
            ],
            'reason' => $this->reason,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reviewer' => $this->reviewer?->name,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'cancel' => $user?->can('cancel', $this->resource) ?? false,
                'review' => $user?->can('review', $this->resource) ?? false,
            ],
        ];
    }
}
