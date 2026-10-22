<?php

namespace App\Http\Requests\Api\V1\Account;

use App\Enums\PushPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePushDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::enum(PushPlatform::class)],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
