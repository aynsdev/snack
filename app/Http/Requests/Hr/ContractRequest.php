<?php

namespace App\Http\Requests\Hr;

use App\Enums\ContractStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding or editing a contract. The file is optional on both: a contract can
 * be recorded before the signed copy comes back.
 */
class ContractRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:160'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'status' => ['required', Rule::enum(ContractStatus::class)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'salary_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'salary_currency' => ['nullable', 'required_with:salary_amount', 'string', 'size:3', 'alpha'],
            'pay_frequency' => ['nullable', Rule::in(['hourly', 'daily', 'weekly', 'semi_monthly', 'monthly', 'yearly'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,png,jpg,jpeg'],
        ];
    }
}
