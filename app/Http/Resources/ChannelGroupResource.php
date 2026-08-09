<?php

namespace App\Http\Resources;

use App\Models\ChannelGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A section of the viewer's channel list, as a heading in the sidebar.
 *
 * Carries no channels of its own: the payload sends every joined channel once,
 * each naming its section, and the client buckets them — the same shape as
 * {@see ProjectGroupResource}, for the same reason.
 *
 * Mirrors the `ChannelGroup` type in resources/js/types/snack.ts.
 *
 * @mixin ChannelGroup
 */
class ChannelGroupResource extends JsonResource
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
