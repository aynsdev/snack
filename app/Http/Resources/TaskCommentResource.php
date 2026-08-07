<?php

namespace App\Http\Resources;

use App\Models\TaskComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One remark on a card, as the panel draws it.
 *
 * Mirrors the `TaskComment` type in resources/js/types/snack.ts.
 *
 * `author` may be null — a deleted account leaves its comments behind, and the
 * panel renders those as "Former member" rather than dropping the remark.
 *
 * @mixin TaskComment
 */
class TaskCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'body' => $this->body,
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'author' => $this->author === null
                ? null
                : (new UserResource($this->author))->resolve($request),

            /*
             * Which controls to draw, answered by the policy rather than by
             * comparing author ids in the client — the rules differ between
             * editing (author only) and deleting (author or a lead), and only
             * one of those is guessable from what the client holds. Every
             * mutation is re-authorized when it arrives.
             */
            'can' => [
                'update' => $user?->can('update', $this->resource) ?? false,
                'delete' => $user?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
