<?php

namespace App\Http\Resources\Hr;

use App\Models\DocumentSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentSubmission
 */
class DocumentSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn (): array => [
                'id' => $this->employee->id,
                'name' => $this->employee->user->name,
                'initials' => $this->employee->user->initials(),
                'avatar_url' => $this->employee->user->avatarUrl(),
            ]),
            'request' => $this->whenLoaded('documentRequest', fn (): array => [
                'id' => $this->documentRequest->id,
                'title' => $this->documentRequest->title,
                'description' => $this->documentRequest->description,
                'allowed_formats' => $this->documentRequest->allowed_formats,
                'max_size_mb' => $this->documentRequest->max_size_mb,
                'due_on' => $this->documentRequest->due_on?->toDateString(),
            ]),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'file_name' => $this->file_name,
            'has_file' => $this->file_path !== null,
            'expires_on' => $this->expires_on?->toDateString(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewer' => $this->reviewer?->name,
            'review_note' => $this->review_note,
            'can' => [
                'upload' => $user?->can('upload', $this->resource) ?? false,
                'review' => $user?->can('review', $this->resource) ?? false,
            ],
        ];
    }
}
