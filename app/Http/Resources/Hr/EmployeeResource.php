<?php

namespace App\Http\Resources\Hr;

use App\Http\Resources\UserResource;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mirrors the `Employee` type in resources/js/types/hr.ts.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => (new UserResource($this->user))->resolve($request),
            'email' => $this->user->email,
            'workspace_role' => $this->membership?->role->value,
            'employee_number' => $this->employee_number,
            'job_title' => $this->job_title,
            'department' => $this->department,
            'employment_type' => $this->employment_type->value,
            'employment_type_label' => $this->employment_type->label(),
            'work_type' => $this->work_type->value,
            'work_type_label' => $this->work_type->label(),
            'shift' => $this->whenLoaded('shift', fn (): ?array => $this->shift === null ? null : [
                'id' => $this->shift->id,
                'name' => $this->shift->name,
                'hours' => $this->shift->hours(),
            ]),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'hired_on' => $this->hired_on?->toDateString(),
            'terminated_on' => $this->terminated_on?->toDateString(),
            'birth_date' => $this->birth_date?->toDateString(),
            'phone' => $this->phone,
            'address' => $this->address,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'manager' => $this->whenLoaded('manager', fn () => $this->manager === null ? null : [
                'id' => $this->manager->id,
                'name' => $this->manager->user->name,
            ]),
            'is_clocked_in' => $this->whenLoaded('openTimeEntry', fn (): bool => $this->openTimeEntry !== null),
        ];
    }
}
