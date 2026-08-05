<?php

namespace App\Http\Resources;

use App\Models\BoardColumn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One column of a board.
 *
 * `category` travels with the name because the client needs it for the same two
 * questions the server does — which column a new card is offered in, and which
 * ones count as finished — and a name like "To fix" answers neither.
 *
 * Mirrors the `BoardColumn` type in resources/js/types/snack.ts.
 *
 * @mixin BoardColumn
 */
class BoardColumnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category->value,
            'position' => $this->position,
            'wip_limit' => $this->wip_limit,
        ];
    }
}
