<?php

namespace App\Http\Requests\Hr;

use App\Enums\AbsenceType;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAbsenceRequest extends FormRequest
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
        return [
            'date' => [
                'required', 'date',
                Rule::unique('absences')->where('employee_id', $this->employee()->id),
            ],
            'type' => ['required', Rule::enum(AbsenceType::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['date.unique' => 'An absence is already recorded on that day.'];
    }

    private function employee(): Employee
    {
        $employee = $this->route('employee');

        abort_unless($employee instanceof Employee, 404);

        return $employee;
    }
}
