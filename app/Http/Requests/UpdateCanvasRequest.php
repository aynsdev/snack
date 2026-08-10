<?php

namespace App\Http\Requests;

use App\Services\CanvasDocument;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateCanvasRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('canvas'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'content' => ['required', 'array'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $content = $this->input('content');

                if (! is_array($content) || ! app(CanvasDocument::class)->isValid($content)) {
                    $validator->errors()->add('content', 'The Canvas document is invalid or too large.');
                }
            },
        ];
    }
}
