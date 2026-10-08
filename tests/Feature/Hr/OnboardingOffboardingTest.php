<?php

use App\Enums\AuditAction;
use App\Enums\ChecklistKind;
use App\Enums\ChecklistStatus;
use App\Enums\EmploymentChange;
use App\Enums\EmploymentStatus;
use App\Enums\ResignationStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\ChecklistTemplate;
use App\Models\EmployeeChecklist;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 09:00:00');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->manager = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->employee = $this->member->employeeIn($this->workspace);
    $this->employee->update(['manager_id' => $this->manager->employeeIn($this->workspace)->id]);

    ChecklistTemplate::seedDefaultsFor($this->workspace);
    $this->onboarding = ChecklistTemplate::defaultFor($this->workspace, ChecklistKind::Onboarding);
    $this->offboarding = ChecklistTemplate::defaultFor($this->workspace, ChecklistKind::Offboarding);

    $this->base = "/w/{$this->workspace->slug}/hr";
});

function startChecklist(object $test, ChecklistTemplate $template, array $overrides = [])
{
    return $test->actingAs($test->admin)->post("{$test->base}/checklists", [
        'employee_id' => $test->employee->id,
        'checklist_template_id' => $template->id,
        'starts_on' => '2026-09-29',
        'target_on' => '2026-10-05',
        ...$overrides,
    ]);
}

describe('templates', function () {
    it('seeds a default onboarding and offboarding template once', function () {
        ChecklistTemplate::seedDefaultsFor($this->workspace);

        expect($this->workspace->checklistTemplates()->count())->toBe(2)
            ->and($this->onboarding->tasks)->toHaveCount(count(ChecklistTemplate::DEFAULTS['onboarding']['tasks']));
    });

    it('lets HR save a template with its steps, replacing them on edit', function () {
        $this->actingAs($this->admin)
            ->post("{$this->base}/checklist-templates", [
                'kind' => 'onboarding', 'name' => 'Engineers', 'is_default' => true,
                'tasks' => [
                    ['title' => 'Repo access', 'assignee_role' => 'hr', 'due_offset_days' => 0],
                    ['title' => 'Pair with a buddy', 'assignee_role' => 'manager', 'due_offset_days' => 2],
                ],
            ])
            ->assertSessionHasNoErrors();

        $template = $this->workspace->checklistTemplates()->where('name', 'Engineers')->sole();

        expect($template->is_default)->toBeTrue()
            ->and($this->onboarding->fresh()->is_default)->toBeFalse()
            ->and($template->tasks->pluck('title')->all())->toBe(['Repo access', 'Pair with a buddy']);

        $this->actingAs($this->admin)->patch("{$this->base}/checklist-templates/{$template->id}", [
            'name' => 'Engineers', 'tasks' => [['title' => 'Only step', 'assignee_role' => 'employee', 'due_offset_days' => 1]],
        ])->assertSessionHasNoErrors();

        expect($template->fresh()->tasks->pluck('title')->all())->toBe(['Only step']);
    });

    it('keeps the last template of a kind, and members out', function () {
        $this->actingAs($this->admin)->delete("{$this->base}/checklist-templates/{$this->onboarding->id}")->assertSessionHas('error');
        expect($this->onboarding->fresh())->not->toBeNull();

        $this->actingAs($this->member)
            ->post("{$this->base}/checklist-templates", ['kind' => 'onboarding', 'name' => 'x', 'tasks' => [['title' => 'x', 'assignee_role' => 'hr', 'due_offset_days' => 0]]])
            ->assertForbidden();
    });
});

describe('checklists', function () {
    it('copies the steps, gives each to a person, and dates them from the target day', function () {
        startChecklist($this, $this->onboarding)->assertSessionHasNoErrors();

        $checklist = $this->employee->checklists()->sole();
        $tasks = $checklist->tasks;

        expect($checklist->status)->toBe(ChecklistStatus::InProgress)
            ->and($tasks)->toHaveCount($this->onboarding->tasks->count())
            ->and($tasks[0]->assignee_id)->toBe($this->admin->id)
            ->and($tasks[0]->due_on->toDateString())->toBe('2026-10-03')
            ->and($tasks[2]->assignee_id)->toBe($this->manager->id)
            ->and($tasks[3]->assignee_id)->toBe($this->member->id)
            ->and(AuditLog::query()->where('action', AuditAction::ChecklistStarted)->exists())->toBeTrue();
    });

    it('refuses a second checklist of the same kind while one is running', function () {
        startChecklist($this, $this->onboarding);

        startChecklist($this, $this->onboarding)->assertSessionHasErrors('employee_id');
    });

    it('lets the assignee or HR tick a step, and nobody else', function () {
        startChecklist($this, $this->onboarding);
        $own = $this->employee->checklists()->sole()->tasks->firstWhere('assignee_id', $this->member->id);
        $managers = $this->employee->checklists()->sole()->tasks->firstWhere('assignee_id', $this->manager->id);

        $this->actingAs($this->member)->post("{$this->base}/checklist-tasks/{$own->id}/toggle")->assertRedirect();
        expect($own->fresh()->completed_by)->toBe($this->member->id);

        $this->actingAs($this->member)->post("{$this->base}/checklist-tasks/{$managers->id}/toggle")->assertNotFound();
        $this->actingAs($this->admin)->post("{$this->base}/checklist-tasks/{$managers->id}/toggle")->assertRedirect();
        expect($managers->fresh()->completed_at)->not->toBeNull();

        $this->actingAs($this->member)->post("{$this->base}/checklist-tasks/{$own->id}/toggle");
        expect($own->fresh()->completed_at)->toBeNull();
    });

    it('shows people their own steps on the HR home', function () {
        startChecklist($this, $this->onboarding);

        $this->actingAs($this->manager)
            ->get($this->base)
            ->assertInertia(fn (Assert $page) => $page
                ->has('my_tasks', 3)
                ->where('my_tasks.0.checklist.employee', $this->member->name)
                ->where('my_tasks.0.can_toggle', true));
    });

    it('ends the employment when offboarding is completed', function () {
        startChecklist($this, $this->offboarding, ['target_on' => '2026-10-15']);
        $checklist = $this->employee->checklists()->sole();

        $this->actingAs($this->member)->post("{$this->base}/checklists/{$checklist->id}/complete")->assertForbidden();
        $this->actingAs($this->admin)->post("{$this->base}/checklists/{$checklist->id}/complete")->assertSessionHasNoErrors();

        $employee = $this->employee->fresh();

        expect($checklist->fresh()->status)->toBe(ChecklistStatus::Completed)
            ->and($employee->status)->toBe(EmploymentStatus::Terminated)
            ->and($employee->terminated_on->toDateString())->toBe('2026-10-15')
            ->and($employee->employmentHistories()->where('change', EmploymentChange::Terminated)->exists())->toBeTrue();

        $this->actingAs($this->admin)->post("{$this->base}/checklists/{$checklist->id}/cancel")->assertForbidden();
    });

    it('leaves the employment alone when onboarding is completed or offboarding cancelled', function () {
        startChecklist($this, $this->offboarding);
        $checklist = $this->employee->checklists()->sole();

        $this->actingAs($this->admin)->post("{$this->base}/checklists/{$checklist->id}/cancel");

        expect($checklist->fresh()->status)->toBe(ChecklistStatus::Cancelled)
            ->and($this->employee->fresh()->status)->toBe(EmploymentStatus::Active);
    });

    it('shows the boards to HR only', function () {
        startChecklist($this, $this->onboarding);

        $this->actingAs($this->admin)
            ->get("{$this->base}/onboarding")
            ->assertInertia(fn (Assert $page) => $page
                ->component('hr/checklists')
                ->where('kind', 'onboarding')
                ->has('active', 1)
                ->where('active.0.done', 0)
                ->has('templates', 1));

        $this->actingAs($this->member)->get("{$this->base}/onboarding")->assertForbidden();
        $this->actingAs($this->member)->get("{$this->base}/offboarding")->assertForbidden();
    });

    it('does not reach another workspace\'s checklist', function () {
        [$other, $otherOwner] = makeWorkspaceWithOwner();
        ChecklistTemplate::seedDefaultsFor($other);
        $foreign = EmployeeChecklist::query()->create([
            'workspace_id' => $other->id, 'employee_id' => $otherOwner->employeeIn($other)->id,
            'kind' => 'onboarding', 'name' => 'x', 'status' => 'in_progress', 'starts_on' => '2026-09-29',
        ]);

        $this->actingAs($this->admin)->post("{$this->base}/checklists/{$foreign->id}/complete")->assertNotFound();
    });
});

describe('resignations', function () {
    it('starts offboarding to the last day when HR accepts', function () {
        $this->actingAs($this->member)
            ->post("{$this->base}/resignations", ['reason' => 'Moving abroad', 'last_day_on' => '2026-10-30'])
            ->assertSessionHasNoErrors();

        $resignation = $this->employee->resignations()->sole();

        $this->actingAs($this->member)
            ->post("{$this->base}/resignations", ['reason' => 'Again', 'last_day_on' => '2026-10-30'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post("{$this->base}/resignations/{$resignation->id}/accept")
            ->assertSessionHasNoErrors();

        $checklist = $this->employee->checklists()->sole();

        expect($resignation->fresh()->status)->toBe(ResignationStatus::Approved)
            ->and($checklist->kind)->toBe(ChecklistKind::Offboarding)
            ->and($checklist->target_on->toDateString())->toBe('2026-10-30')
            ->and(AuditLog::query()->where('action', AuditAction::ResignationAccepted)->exists())->toBeTrue();

        $this->actingAs($this->member)
            ->post("{$this->base}/resignations", ['reason' => 'Once more', 'last_day_on' => '2026-11-30'])
            ->assertForbidden();
    });

    it('lets only the person withdraw, and only HR decide', function () {
        $resignation = $this->employee->resignations()->create([
            'workspace_id' => $this->workspace->id, 'reason' => 'x', 'last_day_on' => '2026-10-30', 'status' => ResignationStatus::Pending,
        ]);

        $this->actingAs($this->member)->post("{$this->base}/resignations/{$resignation->id}/accept")->assertForbidden();
        $this->actingAs($this->admin)->post("{$this->base}/resignations/{$resignation->id}/withdraw")->assertNotFound();
        $this->actingAs($this->member)->post("{$this->base}/resignations/{$resignation->id}/withdraw")->assertSessionHasNoErrors();

        expect($resignation->fresh()->status)->toBe(ResignationStatus::Withdrawn)
            ->and($this->employee->checklists()->count())->toBe(0);
    });

    it('refuses a last day in the past', function () {
        $this->actingAs($this->member)
            ->post("{$this->base}/resignations", ['reason' => 'x', 'last_day_on' => '2026-09-01'])
            ->assertSessionHasErrors('last_day_on');
    });

    it('shows the resignation on the person\'s own file, and offers to resign only there', function () {
        $this->actingAs($this->member)
            ->get("{$this->base}/employees/{$this->employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can_resign', true)->where('resignation', null)->where('checklists', []));

        $this->actingAs($this->admin)
            ->get("{$this->base}/employees/{$this->employee->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can_resign', false));
    });

    it('queues resignations on the offboarding board and the dashboard', function () {
        $this->employee->resignations()->create([
            'workspace_id' => $this->workspace->id, 'reason' => 'x', 'last_day_on' => '2026-10-30', 'status' => ResignationStatus::Pending,
        ]);

        $this->actingAs($this->admin)
            ->get("{$this->base}/offboarding")
            ->assertInertia(fn (Assert $page) => $page->has('resignations', 1)->where('resignations.0.can.review', true));

        $this->actingAs($this->admin)
            ->get("{$this->base}/dashboard")
            ->assertInertia(fn (Assert $page) => $page
                ->where('pending_requests.resignations', 1)
                ->where('kpis.pending_approvals', 1));
    });
});

describe('automatic onboarding', function () {
    it('starts the default onboarding for a new member when switched on', function () {
        $this->actingAs($this->admin)
            ->patch("{$this->base}/settings", ['auto_onboarding' => true])
            ->assertSessionHasNoErrors();

        $newcomer = joinWorkspace($this->workspace);
        $checklist = $newcomer->employeeIn($this->workspace)->checklists()->sole();

        expect($checklist->kind)->toBe(ChecklistKind::Onboarding)
            ->and($checklist->started_by)->toBeNull()
            ->and($checklist->tasks->firstWhere('assignee_role.value', 'hr')->assignee_id)->toBe($this->owner->id);
    });

    it('does nothing when switched off, and keeps other settings when toggled', function () {
        $this->workspace->update(['settings' => ['hr' => ['require_location' => true]]]);

        $this->actingAs($this->admin)->patch("{$this->base}/settings", ['auto_onboarding' => false]);

        expect($this->workspace->fresh()->setting('hr.require_location'))->toBeTrue();

        $newcomer = joinWorkspace($this->workspace);
        expect($newcomer->employeeIn($this->workspace)->checklists()->count())->toBe(0);
    });
});
