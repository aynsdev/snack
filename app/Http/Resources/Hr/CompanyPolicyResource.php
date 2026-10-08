<?php

namespace App\Http\Resources\Hr;

use App\Models\CompanyPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A handbook page. `acknowledged` is whether the viewer has; `acknowledged_count`
 * is filled in for HR only.
 *
 * @mixin CompanyPolicy
 */
class CompanyPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'requires_acknowledgement' => $this->requires_acknowledgement,
            'file_name' => $this->file_name,
            'has_file' => $this->file_path !== null,
            'author' => $this->author?->name,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'acknowledged' => (bool) ($this->acknowledged ?? false),
            'acknowledged_count' => $this->acknowledgements_count ?? null,
            'can' => [
                'acknowledge' => $request->user()?->can('acknowledge', $this->resource) ?? false,
            ],
        ];
    }
}
