<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

class StoreCanvasCoverRequest extends FormRequest
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
            'cover' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,gif,webp',
                'max:10240',
            ],
        ];
    }

    public function cover(): ?UploadedFile
    {
        $cover = $this->file('cover');

        return $cover instanceof UploadedFile ? $cover : null;
    }
}
