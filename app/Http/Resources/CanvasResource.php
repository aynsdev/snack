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

        /*
         * The whole document only where one canvas is being opened or has just
         * been written — never in a list. The mobile API's single-canvas
         * responses count as opening it.
         */
        $isSingle = $request->routeIs(
            'canvases.show',
            'api.v1.canvases.show',
            'api.v1.canvases.store',
            'api.v1.canvases.update',
            'api.v1.canvases.cover.store',
            'api.v1.channels.canvases.store',
            'api.v1.conversations.canvases.store',
        );

        return [
            'id' => $canvas->public_id,
            'title' => $canvas->title,
            'body' => $this->when($isSingle, $canvas->body),
            'content' => $this->when($isSingle, $canvas->content),
            'cover_url' => $this->when(
                $isSingle,
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
            'is_starred' => (bool) $canvas->getAttribute('is_starred'),
            'container' => $this->when(
                $isSingle || $request->routeIs('canvases.index', 'api.v1.canvases.index'),
                function () use ($canvas, $user): ?array {
                    if ($canvas->channel !== null) {
                        return [
                            'type' => 'channel',
                            // The route key, for a client that navigates by API rather than by `url`.
                            'key' => $canvas->channel->getRouteKey(),
                            'label' => '#'.$canvas->channel->name,
                            'url' => route('channels.show', [$canvas->workspace, $canvas->channel]),
                        ];
                    }

                    if ($canvas->conversation !== null && $user !== null) {
                        return [
                            'type' => 'conversation',
                            'key' => $canvas->conversation->getRouteKey(),
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
