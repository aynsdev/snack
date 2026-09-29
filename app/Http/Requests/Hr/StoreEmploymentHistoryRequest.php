<?php

namespace App\Http\Requests\Hr;

use App\Enums\EmploymentChange;
use App\Enums\EmploymentType;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmploymentHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return $this->user()?->can('update', $employee) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'change' => ['required', Rule::enum(EmploymentChange::class)],
            'effective_on' => ['required', 'date'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'employment_type' => ['nullable', Rule::enum(EmploymentType::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'apply_to_profile' => ['boolean'],
        ];
    }
}
