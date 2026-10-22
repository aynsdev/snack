<?php

use App\Enums\WorkspaceRole;
use App\Models\User;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->base = "/api/v1/w/{$this->workspace->slug}/hr/settings";
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };
});

it('shows the HR settings with seeded leave types and templates', function () {
    ($this->as)($this->owner)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.geofence', null)
        ->assertJsonPath('data.require_location', false)
        ->assertJsonPath('data.auto_onboarding', false)
        ->assertJsonCount(2, 'data.checklist_templates')
        ->assertJsonStructure(['data' => ['leave_types', 'shifts']]);
});

it('saves only the fields sent', function () {
    ($this->as)($this->owner)
        ->patchJson($this->base, [
            'require_location' => true,
            'geofence' => ['latitude' => 14.5547, 'longitude' => 121.0244, 'radius_meters' => 150, 'enforced' => true],
        ])
        ->assertOk()
        ->assertJsonPath('data.require_location', true)
        ->assertJsonPath('data.geofence.radius_meters', 150);

    ($this->as)($this->owner)
        ->patchJson($this->base, ['auto_onboarding' => true])
        ->assertOk()
        ->assertJsonPath('data.auto_onboarding', true)
        ->assertJsonPath('data.require_location', true);

    expect($this->workspace->fresh()->setting('hr.geofence.radius_meters'))->toBe(150);
});

it('is HR only', function () {
    ($this->as)($this->member)->getJson($this->base)->assertForbidden();
    ($this->as)($this->member)->patchJson($this->base, ['require_location' => false])->assertForbidden();
    ($this->as)($this->owner)->patchJson($this->base, ['geofence' => ['latitude' => 100]])->assertUnprocessable();
});
