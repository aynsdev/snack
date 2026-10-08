<?php

use App\Enums\AuditAction;
use App\Enums\ChecklistKind;
use App\Enums\EquipmentStatus;
use App\Enums\RequestStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\ChecklistTemplate;
use App\Models\Equipment;
use App\Models\EquipmentRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

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

    $this->base = "/w/{$this->workspace->slug}/hr";
});

function handOut(object $test, ?Equipment $equipment = null, ?int $employeeId = null)
{
    $equipment ??= $test->laptop;

    return $test->actingAs($test->admin)->post("{$test->base}/equipment/{$equipment->id}/assign", [
        'employee_id' => $employeeId ?? $test->employee->id,
        'condition' => 'New in box',
    ]);
}

describe('inventory', function () {
    it('lets HR add, edit, and list equipment with counts by status', function () {
        $this->actingAs($this->admin)
            ->post("{$this->base}/equipment", [
                'equipment_category_id' => $this->phones->id, 'name' => 'Pixel 9', 'tracking_code' => 'PH-001',
                'cost' => 899.5, 'status' => 'available',
            ])
            ->assertSessionHasNoErrors();

        $phone = $this->workspace->equipment()->where('name', 'Pixel 9')->sole();
        expect($phone->cost)->toBe('899.50')
            ->and(AuditLog::query()->where('action', AuditAction::EquipmentCreated)->exists())->toBeTrue();

        $this->actingAs($this->admin)
            ->post("{$this->base}/equipment", ['equipment_category_id' => $this->phones->id, 'name' => 'Dup', 'tracking_code' => 'PH-001', 'status' => 'available'])
            ->assertSessionHasErrors('tracking_code');

        $this->actingAs($this->admin)
            ->get("{$this->base}/equipment")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('hr/equipment')
                ->has('equipment', 2)
                ->where('status_counts.0.count', 2)
                ->has('categories', 2));
    });

    it('is HR only', function () {
        $this->actingAs($this->member)->get("{$this->base}/equipment")->assertForbidden();
        $this->actingAs($this->member)
            ->post("{$this->base}/equipment", ['equipment_category_id' => $this->laptops->id, 'name' => 'x', 'status' => 'available'])
            ->assertForbidden();
    });

    it('keeps another workspace\'s equipment and categories out', function () {
        [$other] = makeWorkspaceWithOwner();
        $category = $other->equipmentCategories()->create(['name' => 'Laptops']);
        $foreign = $other->equipment()->create(['equipment_category_id' => $category->id, 'name' => 'x', 'status' => 'available']);

        $this->actingAs($this->admin)->delete("{$this->base}/equipment/{$foreign->id}")->assertNotFound();
        $this->actingAs($this->admin)
            ->post("{$this->base}/equipment", ['equipment_category_id' => $category->id, 'name' => 'x', 'status' => 'available'])
            ->assertSessionHasErrors('equipment_category_id');
    });

    it('will not delete a category with equipment, or equipment that is handed out', function () {
        $this->actingAs($this->admin)->delete("{$this->base}/equipment-categories/{$this->laptops->id}")->assertSessionHas('error');
        $this->actingAs($this->admin)->delete("{$this->base}/equipment-categories/{$this->phones->id}")->assertSessionHas('success');

        handOut($this);

        $this->actingAs($this->admin)->delete("{$this->base}/equipment/{$this->laptop->id}")->assertSessionHas('error');
        expect($this->laptop->fresh())->not->toBeNull();
    });

    it('does not let a status be set to assigned by hand, or changed while handed out', function () {
        $this->actingAs($this->admin)
            ->patch("{$this->base}/equipment/{$this->laptop->id}", ['equipment_category_id' => $this->laptops->id, 'name' => 'MacBook Air', 'status' => 'assigned'])
            ->assertSessionHasErrors('status');

        handOut($this);

        $this->actingAs($this->admin)
            ->patch("{$this->base}/equipment/{$this->laptop->id}", ['equipment_category_id' => $this->laptops->id, 'name' => 'MacBook Air', 'status' => 'retired'])
            ->assertSessionHasErrors('status');
    });
});

describe('hand-overs', function () {
    it('hands equipment out and takes it back, keeping the history', function () {
        handOut($this)->assertSessionHasNoErrors();

        $assignment = $this->employee->equipmentAssignments()->sole();
        expect($this->laptop->fresh()->status)->toBe(EquipmentStatus::Assigned)
            ->and($assignment->condition_out)->toBe('New in box')
            ->and(AuditLog::query()->where('action', AuditAction::EquipmentAssigned)->exists())->toBeTrue();

        handOut($this, employeeId: $this->colleague->employeeIn($this->workspace)->id)->assertSessionHasErrors('equipment_id');

        $this->actingAs($this->admin)
            ->post("{$this->base}/equipment-assignments/{$assignment->id}/return", ['status' => 'in_repair', 'condition' => 'Cracked screen'])
            ->assertSessionHasNoErrors();

        expect($this->laptop->fresh()->status)->toBe(EquipmentStatus::InRepair)
            ->and($assignment->fresh()->returned_on->toDateString())->toBe('2026-09-29')
            ->and($assignment->fresh()->condition_in)->toBe('Cracked screen');

        $this->actingAs($this->admin)->post("{$this->base}/equipment-assignments/{$assignment->id}/return", ['status' => 'available'])->assertForbidden();

        $this->actingAs($this->admin)
            ->get("{$this->base}/equipment")
            ->assertInertia(fn (Assert $page) => $page->where('equipment.0.holder', null)->has('equipment.0.history', 1));
    });

    it('lets the holder ask to return it, once, and nobody else', function () {
        handOut($this);
        $assignment = $this->employee->equipmentAssignments()->sole();

        $this->actingAs($this->colleague)->post("{$this->base}/equipment-assignments/{$assignment->id}/request-return")->assertNotFound();
        $this->actingAs($this->member)->post("{$this->base}/equipment-assignments/{$assignment->id}/request-return")->assertSessionHasNoErrors();
        $this->actingAs($this->member)->post("{$this->base}/equipment-assignments/{$assignment->id}/request-return")->assertForbidden();

        expect($assignment->fresh()->return_requested_at)->not->toBeNull();

        $this->actingAs($this->member)->post("{$this->base}/equipment-assignments/{$assignment->id}/return", ['status' => 'available'])->assertForbidden();
    });

    it('shows people what they have on their own page and file', function () {
        handOut($this);

        $this->actingAs($this->member)
            ->get("{$this->base}/my-equipment")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('hr/my-equipment')
                ->has('current', 1)
                ->where('current.0.equipment.tracking_code', 'LAP-001')
                ->where('current.0.can.request_return', true));

        $this->actingAs($this->member)
            ->get("{$this->base}/employees/{$this->employee->id}")
            ->assertInertia(fn (Assert $page) => $page->has('equipment', 1));
    });
});

describe('equipment requests', function () {
    it('lets HR approve and hand over an available item of the kind asked for in one step', function () {
        $this->actingAs($this->member)
            ->post("{$this->base}/equipment-requests", ['equipment_category_id' => $this->laptops->id, 'reason' => 'Mine died'])
            ->assertSessionHasNoErrors();

        $request = EquipmentRequest::query()->sole();
        $phone = $this->workspace->equipment()->create(['equipment_category_id' => $this->phones->id, 'name' => 'Pixel', 'status' => 'available']);

        $this->actingAs($this->admin)
            ->post("{$this->base}/equipment-requests/{$request->id}/approve", ['equipment_id' => $phone->id])
            ->assertSessionHasErrors('equipment_id');

        $this->actingAs($this->admin)
            ->post("{$this->base}/equipment-requests/{$request->id}/approve", ['equipment_id' => $this->laptop->id])
            ->assertSessionHasNoErrors();

        $request->refresh();
        expect($request->status)->toBe(RequestStatus::Approved)
            ->and($request->assignment->equipment_id)->toBe($this->laptop->id)
            ->and($this->laptop->fresh()->status)->toBe(EquipmentStatus::Assigned)
            ->and(AuditLog::query()->where('action', AuditAction::EquipmentRequestApproved)->exists())->toBeTrue();
    });

    it('can be approved without handing anything over yet, or rejected', function () {
        $first = $this->employee->equipmentRequests()->create([
            'workspace_id' => $this->workspace->id, 'equipment_category_id' => $this->phones->id, 'status' => RequestStatus::Pending,
        ]);
        $second = $this->employee->equipmentRequests()->create([
            'workspace_id' => $this->workspace->id, 'equipment_category_id' => $this->phones->id, 'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($this->admin)->post("{$this->base}/equipment-requests/{$first->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("{$this->base}/equipment-requests/{$second->id}/reject", ['note' => 'None left'])->assertSessionHasNoErrors();

        expect($first->fresh()->status)->toBe(RequestStatus::Approved)
            ->and($first->fresh()->equipment_assignment_id)->toBeNull()
            ->and($second->fresh()->status)->toBe(RequestStatus::Rejected);
    });

    it('lets the asker withdraw, and keeps members from deciding', function () {
        $request = $this->employee->equipmentRequests()->create([
            'workspace_id' => $this->workspace->id, 'equipment_category_id' => $this->phones->id, 'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($this->colleague)->post("{$this->base}/equipment-requests/{$request->id}/approve")->assertForbidden();
        $this->actingAs($this->member)->post("{$this->base}/equipment-requests/{$request->id}/cancel")->assertSessionHasNoErrors();

        expect($request->fresh()->status)->toBe(RequestStatus::Cancelled);
    });

    it('queues requests and returns for HR', function () {
        $this->employee->equipmentRequests()->create([
            'workspace_id' => $this->workspace->id, 'equipment_category_id' => $this->phones->id, 'status' => RequestStatus::Pending,
        ]);
        handOut($this);
        $assignment = $this->employee->equipmentAssignments()->sole();
        $this->actingAs($this->member)->post("{$this->base}/equipment-assignments/{$assignment->id}/request-return");

        $this->actingAs($this->admin)
            ->get("{$this->base}/equipment")
            ->assertInertia(fn (Assert $page) => $page->has('requests', 1)->where('requests.0.type', 'equipment'));

        $this->actingAs($this->admin)
            ->get("{$this->base}/dashboard")
            ->assertInertia(fn (Assert $page) => $page
                ->where('pending_requests.equipment', 1)
                ->where('pending_requests.returns', 1)
                ->where('kpis.pending_approvals', 2));
    });
});

it('shows what somebody being offboarded still has to hand back', function () {
    handOut($this);
    ChecklistTemplate::seedDefaultsFor($this->workspace);
    $template = ChecklistTemplate::defaultFor($this->workspace, ChecklistKind::Offboarding);

    $this->actingAs($this->admin)->post("{$this->base}/checklists", [
        'employee_id' => $this->employee->id, 'checklist_template_id' => $template->id,
        'starts_on' => '2026-09-29', 'target_on' => '2026-10-15',
    ]);

    $this->actingAs($this->admin)
        ->get("{$this->base}/offboarding")
        ->assertInertia(fn (Assert $page) => $page->where('active.0.equipment_held', 1));
});

it('keeps strangers out of the equipment page', function () {
    $this->actingAs(User::factory()->create())->get("{$this->base}/my-equipment")->assertNotFound();
});
