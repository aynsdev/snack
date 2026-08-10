<?php

namespace App\Http\Resources;

use App\Models\Canvas;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin Canvas */
class CanvasResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Canvas $canvas */
        $canvas = $this->resource;
        $user = $request->user();

        return [
            'id' => $canvas->public_id,
            'title' => $canvas->title,
            'body' => $this->when($request->routeIs('canvases.show'), $canvas->body),
            'content' => $this->when($request->routeIs('canvases.show'), $canvas->content),
            'cover_url' => $this->when(
                $request->routeIs('canvases.show'),
                fn () => $canvas->cover === null
                    ? null
                    : AttachmentResource::payload($canvas->cover)['preview_url'],
            ),
            'excerpt' => Str::limit(preg_replace('/\s+/', ' ', $canvas->body) ?? '', 180),
            'creator' => $canvas->creator === null
                ? null
                : (new UserResource($canvas->creator))->resolve($request),
            'editor' => $canvas->editor === null
                ? null
                : (new UserResource($canvas->editor))->resolve($request),
            'created_at' => $canvas->created_at?->toIso8601String(),
            'updated_at' => $canvas->updated_at?->toIso8601String(),
            'can_update' => $user?->can('update', $canvas) ?? false,
            'can_delete' => $user?->can('delete', $canvas) ?? false,
            'container' => $this->when(
                $request->routeIs('canvases.show'),
                function () use ($canvas, $user): ?array {
                    if ($canvas->channel !== null) {
                        return [
                            'type' => 'channel',
                            'label' => '#'.$canvas->channel->name,
                            'url' => route('channels.show', [$canvas->workspace, $canvas->channel]),
                        ];
                    }

                    if ($canvas->conversation !== null && $user !== null) {
                        return [
                            'type' => 'conversation',
                            'label' => $canvas->conversation->displayNameFor($user),
                            'url' => route('conversations.show', [$canvas->workspace, $canvas->conversation]),
                        ];
                    }

                    return null;
                },
            ),
        ];
    }
}
