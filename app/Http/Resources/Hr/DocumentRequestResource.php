<?php

namespace App\Http\Resources\Hr;

use App\Enums\DocumentStatus;
use App\Models\DocumentRequest;
use App\Models\DocumentSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request with everybody's answer to it — HR's view.
 *
 * @mixin DocumentRequest
 */
class DocumentRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'allowed_formats' => $this->allowed_formats,
            'max_size_mb' => $this->max_size_mb,
            'due_on' => $this->due_on?->toDateString(),
            'created_by' => $this->creator?->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'total' => $this->submissions->count(),
            'uploaded' => $this->submissions->filter(fn (DocumentSubmission $submission): bool => ! $submission->status->acceptsUpload())->count(),
            'approved' => $this->submissions->where('status', DocumentStatus::Approved)->count(),
            'submissions' => DocumentSubmissionResource::collection($this->submissions)->resolve($request),
        ];
    }
}
