<?php

namespace App\Http\Requests\Hr;

use App\Enums\EmployeeRecordType;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRecordRequest extends FormRequest
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
            'type' => ['required', Rule::enum(EmployeeRecordType::class)],
            'title' => ['required', 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:10000'],
            'recorded_on' => ['required', 'date'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,png,jpg,jpeg,xlsx,csv,txt'],
        ];
    }
}
