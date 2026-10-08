<?php

namespace App\Http\Requests\Hr;

use App\Models\Resignation;
use Illuminate\Foundation\Http\FormRequest;

class ReviewResignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Resignation $resignation */
        $resignation = $this->route('resignation');

        return $this->user()?->can('review', $resignation) ?? false;
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
}
