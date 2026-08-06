<?php

namespace App\Http\Resources;

use App\Models\ProjectGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shelf of projects, as a heading on the project list.
 *
 * Carries no projects of its own: the list sends every project once, each
 * naming its group, and the client buckets them. Nesting them here would mean
 * sending a project twice the moment anything wanted a flat list too.
 *
 * Mirrors the `ProjectGroup` type in resources/js/types/snack.ts.
 *
 * @mixin ProjectGroup
 */
class ProjectGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
        ];
    }
}
