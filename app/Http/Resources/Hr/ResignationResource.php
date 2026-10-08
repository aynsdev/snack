<?php

namespace App\Http\Resources\Hr;

use App\Models\Resignation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Resignation
 */
class ResignationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'employee' => [
                'id' => $this->employee->id,
                'name' => $this->employee->user->name,
                'initials' => $this->employee->user->initials(),
                'avatar_url' => $this->employee->user->avatarUrl(),
            ],
            'reason' => $this->reason,
            'last_day_on' => $this->last_day_on->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reviewer' => $this->reviewer?->name,
            'review_note' => $this->review_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'withdraw' => $user?->can('withdraw', $this->resource) ?? false,
                'review' => $user?->can('review', $this->resource) ?? false,
            ],
        ];
    }
}
