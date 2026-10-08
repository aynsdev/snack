<?php

namespace App\Http\Requests\Hr;

use App\Models\DocumentSubmission;
use Illuminate\Foundation\Http\FormRequest;

class ReviewDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DocumentSubmission $submission */
        $submission = $this->route('documentSubmission');

        return $this->user()?->can('review', $submission) ?? false;
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
