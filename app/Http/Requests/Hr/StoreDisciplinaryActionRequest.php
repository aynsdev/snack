<?php

namespace App\Http\Requests\Hr;

use App\Enums\DisciplinaryActionType;
use App\Models\DisciplinaryAction;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDisciplinaryActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return $this->user()?->can('create', [DisciplinaryAction::class, $employee]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DisciplinaryActionType::class)],
            'description' => ['required', 'string', 'max:5000'],
            'starts_on' => ['required', 'date'],
            'duration_days' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === DisciplinaryActionType::Suspension->value),
                'nullable', 'integer', 'between:1,365',
            ],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,png,jpg,jpeg'],
        ];
    }

    /**
     * @return array{type: string, description: string, starts_on: string, duration_days: int|null}
     */
    public function action(): array
    {
        $type = DisciplinaryActionType::from((string) $this->validated('type'));

        return [
            'type' => $type->value,
            'description' => (string) $this->validated('description'),
            'starts_on' => (string) $this->validated('starts_on'),
            'duration_days' => $type->hasDuration() ? (int) $this->validated('duration_days') : null,
        ];
    }
}
