<?php

namespace App\Http\Requests\Hr;

use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHrSettingsRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        return $this->user()?->roleIn($context->workspace())?->canManagePeople() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'require_location' => ['boolean'],
            'geofence' => ['nullable', 'array'],
            'geofence.latitude' => ['required_with:geofence', 'numeric', 'between:-90,90'],
            'geofence.longitude' => ['required_with:geofence', 'numeric', 'between:-180,180'],
            'geofence.radius_meters' => ['required_with:geofence', 'integer', 'min:10', 'max:50000'],
            'geofence.enforced' => ['boolean'],
        ];
    }
}
