<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StatusUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['nullable', 'string', 'max:16'],
            'message' => ['nullable', 'string', 'max:80'],
            'expires_in' => ['required', Rule::in(['one_hour', 'four_hours', 'today', 'never'])],
            'clear' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{status_emoji: string|null, status_message: string|null, status_expires_at: Carbon|null}
     */
    public function statusAttributes(): array
    {
        $message = $this->boolean('clear')
            ? null
            : $this->string('message')->trim()->toString();
        $emoji = $this->string('emoji')->trim()->toString();

        if ($message === '') {
            $message = null;
        }

        return [
            'status_emoji' => $message !== null && $emoji !== '' ? $emoji : null,
            'status_message' => $message,
            'status_expires_at' => $message === null ? null : match ($this->string('expires_in')->toString()) {
                'one_hour' => now()->addHour(),
                'four_hours' => now()->addHours(4),
                'today' => now()->endOfDay(),
                'never' => null,
            },
        ];
    }
}
