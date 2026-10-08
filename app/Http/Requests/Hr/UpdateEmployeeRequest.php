<?php

namespace App\Http\Requests\Hr;

use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use App\Enums\WorkType;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->employee()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $employee = $this->employee();

        return [
            'employee_number' => [
                'nullable', 'string', 'max:40',
                Rule::unique('employees')->where('workspace_id', $employee->workspace_id)->ignore($employee->id),
            ],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'status' => ['required', Rule::enum(EmploymentStatus::class)],
            'manager_id' => [
                'nullable', 'integer', Rule::notIn([$employee->id]),
                Rule::exists('employees', 'id')->where('workspace_id', $employee->workspace_id),
            ],
            'shift_id' => [
                'nullable', 'integer',
                Rule::exists('shifts', 'id')->where('workspace_id', $employee->workspace_id),
            ],
            'work_type' => ['sometimes', 'required', Rule::enum(WorkType::class)],
            'hired_on' => ['nullable', 'date'],
            'terminated_on' => ['nullable', 'date', 'after_or_equal:hired_on'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function employee(): Employee
    {
        $employee = $this->route('employee');

        abort_unless($employee instanceof Employee, 404);

        return $employee;
    }
}
