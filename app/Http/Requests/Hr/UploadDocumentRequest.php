<?php

namespace App\Http\Requests\Hr;

use App\Models\DocumentSubmission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An employee uploading their copy of a requested document, in one of the
 * formats and under the size the request allows.
 */
class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('upload', $this->submission()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $request = $this->submission()->documentRequest;

        return [
            'file' => [
                'required', 'file',
                'mimes:'.implode(',', $request->allowed_formats),
                'max:'.($request->max_size_mb * 1024),
            ],
            'expires_on' => ['nullable', 'date', 'after:today'],
        ];
    }

    public function submission(): DocumentSubmission
    {
        $submission = $this->route('documentSubmission');

        abort_unless($submission instanceof DocumentSubmission, 404);

        return $submission;
    }
}
