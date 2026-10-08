<?php

namespace App\Http\Requests\Hr;

use App\Models\HrRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving or rejecting a shift, work-type, or attendance request — whichever
 * the route bound.
 */
class ReviewHrRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review', $this->hrRequest()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function hrRequest(): HrRequest
    {
        $request = collect($this->route()?->parameters() ?? [])->first(fn (mixed $value): bool => $value instanceof HrRequest);

        abort_unless($request instanceof HrRequest, 404);

        return $request;
    }
}
