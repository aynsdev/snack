<?php

use App\Enums\EquipmentStatus;
use App\Enums\RequestStatus;
use App\Enums\WorkspaceRole;
use App\Models\EquipmentRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 09:00:00');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->colleague = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->employee = $this->member->employeeIn($this->workspace);

    $this->laptops = $this->workspace->equipmentCategories()->create(['name' => 'Laptops']);
    $this->phones = $this->workspace->equipmentCategories()->create(['name' => 'Phones']);
    $this->laptop = $this->workspace->equipment()->create([
        'equipment_category_id' => $this->laptops->id, 'name' => 'MacBook Air', 'tracking_code' => 'LAP-001',
        'status' => EquipmentStatus::Available,
    ]);

    $this->base = "/api/v1/w/{$this->workspace->slug}/hr";
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };
    $this->handOut = fn () => ($this->as)($this->admin)->postJson("{$this->base}/equipment/{$this->laptop->id}/assign", [
        'employee_id' => $this->employee->id,
        'condition' => 'New in box',
    ]);
    $this->pendingRequest = fn () => $this->employee->equipmentRequests()->create([
        'workspace_id' => $this->workspace->id, 'equipment_category_id' => $this->phones->id, 'status' => RequestStatus::Pending,
    ]);
});

describe('inventory', function () {
    it('lets HR add, edit, list, and delete equipment', function () {
        $id = ($this->as)($this->admin)
            ->postJson("{$this->base}/equipment", [
                'equipment_category_id' => $this->phones->id, 'name' => 'Pixel 9', 'tracking_code' => 'PH-001',
                'cost' => 899.5, 'status' => 'available',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Pixel 9')
            ->assertJsonPath('data.category', 'Phones')
            ->assertJsonPath('data.holder', null)
            ->json('data.id');

        ($this->as)($this->admin)
            ->patchJson("{$this->base}/equipment/{$id}", ['equipment_category_id' => $this->phones->id, 'name' => 'Pixel 9 Pro', 'status' => 'in_repair'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Pixel 9 Pro')
            ->assertJsonPath('data.status', 'in_repair');

        ($this->as)($this->admin)
            ->getJson("{$this->base}/equipment")
            ->assertOk()
            ->assertJsonCount(2, 'data.equipment')
            ->assertJsonCount(2, 'data.categories')
            ->assertJsonPath('data.status_counts.0.count', 1);

        ($this->as)($this->admin)->deleteJson("{$this->base}/equipment/{$id}")->assertNoContent();
    });

    it('is HR only', function () {
        ($this->as)($this->member)->getJson("{$this->base}/equipment")->assertForbidden();
        ($this->as)($this->member)
            ->postJson("{$this->base}/equipment", ['equipment_category_id' => $this->laptops->id, 'name' => 'x', 'status' => 'available'])
            ->assertForbidden();
    });

    it('manages categories, keeping one that still has equipment', function () {
        $id = ($this->as)($this->admin)
            ->postJson("{$this->base}/equipment-categories", ['name' => 'Monitors', 'description' => 'Screens'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Monitors')
            ->assertJsonPath('data.count', 0)
            ->json('data.id');

        ($this->as)($this->admin)
            ->patchJson("{$this->base}/equipment-categories/{$id}", ['name' => 'Displays'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Displays');

        ($this->as)($this->admin)->deleteJson("{$this->base}/equipment-categories/{$id}")->assertNoContent();
        ($this->as)($this->admin)
            ->deleteJson("{$this->base}/equipment-categories/{$this->laptops->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('equipment_category');
    });
});

describe('hand-overs', function () {
    it('hands equipment out, refuses deleting it meanwhile, and takes it back', function () {
        $assignmentId = ($this->handOut)()
            ->assertCreated()
            ->assertJsonPath('data.equipment.tracking_code', 'LAP-001')
            ->assertJsonPath('data.employee.id', $this->employee->id)
            ->assertJsonPath('data.condition_out', 'New in box')
            ->json('data.id');

        expect($this->laptop->fresh()->status)->toBe(EquipmentStatus::Assigned);

        ($this->as)($this->admin)
            ->deleteJson("{$this->base}/equipment/{$this->laptop->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('equipment');

        ($this->as)($this->admin)
            ->postJson("{$this->base}/equipment-assignments/{$assignmentId}/return", ['status' => 'in_repair', 'condition' => 'Cracked screen'])
            ->assertOk()
            ->assertJsonPath('data.returned_on', '2026-09-29')
            ->assertJsonPath('data.condition_in', 'Cracked screen');

        expect($this->laptop->fresh()->status)->toBe(EquipmentStatus::InRepair);
    });

    it('lets the holder ask to return it, once, and nobody else', function () {
        $assignmentId = ($this->handOut)()->json('data.id');

        ($this->as)($this->colleague)->postJson("{$this->base}/equipment-assignments/{$assignmentId}/request-return")->assertNotFound();
        ($this->as)($this->member)
            ->postJson("{$this->base}/equipment-assignments/{$assignmentId}/request-return")
            ->assertOk()
            ->assertJsonPath('data.can.request_return', false);
        ($this->as)($this->member)->postJson("{$this->base}/equipment-assignments/{$assignmentId}/request-return")->assertForbidden();
        ($this->as)($this->member)->postJson("{$this->base}/equipment-assignments/{$assignmentId}/return", ['status' => 'available'])->assertForbidden();
    });

    it('shows people what they have', function () {
        ($this->handOut)();

        ($this->as)($this->member)
            ->getJson("{$this->base}/my-equipment")
            ->assertOk()
            ->assertJsonCount(1, 'data.current')
            ->assertJsonPath('data.current.0.equipment.tracking_code', 'LAP-001')
            ->assertJsonPath('data.current.0.can.request_return', true)
            ->assertJsonCount(2, 'data.categories')
            ->assertJsonPath('data.can_request', true);

        ($this->as)(User::factory()->create())->getJson("{$this->base}/my-equipment")->assertNotFound();
    });
});

describe('equipment requests', function () {
    it('lets HR approve and hand over an available item of the kind asked for', function () {
        $id = ($this->as)($this->member)
            ->postJson("{$this->base}/equipment-requests", ['equipment_category_id' => $this->laptops->id, 'reason' => 'Mine died'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'equipment')
            ->assertJsonPath('data.status', RequestStatus::Pending->value)
            ->json('data.id');

        $phone = $this->workspace->equipment()->create(['equipment_category_id' => $this->phones->id, 'name' => 'Pixel', 'status' => 'available']);

        ($this->as)($this->admin)
            ->postJson("{$this->base}/equipment-requests/{$id}/approve", ['equipment_id' => $phone->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('equipment_id');

        ($this->as)($this->admin)
            ->postJson("{$this->base}/equipment-requests/{$id}/approve", ['equipment_id' => $this->laptop->id])
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Approved->value);

        expect(EquipmentRequest::query()->findOrFail($id)->assignment->equipment_id)->toBe($this->laptop->id);
    });

    it('can be rejected, or withdrawn by the asker, and members cannot decide', function () {
        $first = ($this->pendingRequest)();
        $second = ($this->pendingRequest)();

        ($this->as)($this->colleague)->postJson("{$this->base}/equipment-requests/{$first->id}/approve")->assertForbidden();

        ($this->as)($this->admin)
            ->postJson("{$this->base}/equipment-requests/{$first->id}/reject", ['note' => 'None left'])
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Rejected->value)
            ->assertJsonPath('data.review_note', 'None left');

        ($this->as)($this->member)
            ->postJson("{$this->base}/equipment-requests/{$second->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', RequestStatus::Cancelled->value);
    });

    it('queues pending requests on the inventory', function () {
        ($this->pendingRequest)();

        ($this->as)($this->admin)
            ->getJson("{$this->base}/equipment")
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.requests.0.type', 'equipment');
    });
});
