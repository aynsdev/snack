<?php

use App\Enums\ChecklistKind;
use App\Enums\ChecklistStatus;
use App\Enums\EmploymentStatus;
use App\Enums\WorkspaceRole;
use App\Models\ChecklistTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;

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

    $this->base = "/api/v1/w/{$this->workspace->slug}/hr";
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };
    $this->start = fn (ChecklistTemplate $template, array $overrides = []) => ($this->as)($this->admin)->postJson("{$this->base}/checklists", [
        'employee_id' => $this->employee->id,
        'checklist_template_id' => $template->id,
        'starts_on' => '2026-09-29',
        'target_on' => '2026-10-05',
        ...$overrides,
    ]);
});

describe('checklists', function () {
    it('starts a checklist and shows it on the board', function () {
        ($this->start)($this->onboarding)
            ->assertCreated()
            ->assertJsonPath('data.kind', 'onboarding')
            ->assertJsonPath('data.employee.id', $this->employee->id)
            ->assertJsonPath('data.total', $this->onboarding->tasks->count());

        ($this->as)($this->admin)
            ->getJson("{$this->base}/onboarding")
            ->assertOk()
            ->assertJsonPath('data.kind', 'onboarding')
            ->assertJsonCount(1, 'data.active')
            ->assertJsonCount(1, 'data.templates')
            ->assertJsonPath('data.resignations', []);

        ($this->as)($this->admin)->getJson("{$this->base}/offboarding")->assertOk()->assertJsonPath('data.kind', 'offboarding');
    });

    it('refuses a second checklist of the same kind while one is running', function () {
        ($this->start)($this->onboarding);

        ($this->start)($this->onboarding)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    });

    it('shows the boards to HR only', function () {
        ($this->as)($this->member)->getJson("{$this->base}/onboarding")->assertForbidden();
        ($this->as)($this->member)->getJson("{$this->base}/offboarding")->assertForbidden();
    });

    it('lets the assignee tick their own step, and nobody else\'s', function () {
        ($this->start)($this->onboarding);
        $tasks = $this->employee->checklists()->sole()->tasks;
        $own = $tasks->firstWhere('assignee_id', $this->member->id);
        $managers = $tasks->firstWhere('assignee_id', $this->manager->id);

        ($this->as)($this->member)
            ->postJson("{$this->base}/checklist-tasks/{$own->id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.id', $own->id)
            ->assertJsonPath('data.completed_by', $this->member->name);

        ($this->as)($this->member)->postJson("{$this->base}/checklist-tasks/{$managers->id}/toggle")->assertNotFound();

        ($this->as)($this->member)
            ->postJson("{$this->base}/checklist-tasks/{$own->id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.completed_at', null);
    });

    it('ends the employment when offboarding is completed', function () {
        ($this->start)($this->offboarding, ['target_on' => '2026-10-15']);
        $checklist = $this->employee->checklists()->sole();

        ($this->as)($this->member)->postJson("{$this->base}/checklists/{$checklist->id}/complete")->assertForbidden();
        ($this->as)($this->admin)
            ->postJson("{$this->base}/checklists/{$checklist->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', ChecklistStatus::Completed->value);

        expect($this->employee->fresh()->status)->toBe(EmploymentStatus::Terminated);
    });

    it('cancels a checklist', function () {
        ($this->start)($this->offboarding);
        $checklist = $this->employee->checklists()->sole();

        ($this->as)($this->admin)
            ->postJson("{$this->base}/checklists/{$checklist->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', ChecklistStatus::Cancelled->value);

        expect($this->employee->fresh()->status)->toBe(EmploymentStatus::Active);
    });
});

describe('templates', function () {
    it('saves a template with its steps, replacing them on edit, and deletes it', function () {
        $id = ($this->as)($this->admin)
            ->postJson("{$this->base}/checklist-templates", [
                'kind' => 'onboarding', 'name' => 'Engineers', 'is_default' => true,
                'tasks' => [
                    ['title' => 'Repo access', 'assignee_role' => 'hr', 'due_offset_days' => 0],
                    ['title' => 'Pair with a buddy', 'assignee_role' => 'manager', 'due_offset_days' => 2],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Engineers')
            ->assertJsonPath('data.is_default', true)
            ->assertJsonCount(2, 'data.tasks')
            ->json('data.id');

        expect($this->onboarding->fresh()->is_default)->toBeFalse();

        ($this->as)($this->admin)
            ->patchJson("{$this->base}/checklist-templates/{$id}", [
                'name' => 'Engineers', 'tasks' => [['title' => 'Only step', 'assignee_role' => 'employee', 'due_offset_days' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('data.tasks.0.title', 'Only step')
            ->assertJsonCount(1, 'data.tasks');

        ($this->as)($this->admin)->deleteJson("{$this->base}/checklist-templates/{$id}")->assertNoContent();

        expect($this->onboarding->fresh()->is_default)->toBeTrue();
    });

    it('keeps the last template of a kind, and members out', function () {
        ($this->as)($this->admin)
            ->deleteJson("{$this->base}/checklist-templates/{$this->onboarding->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('checklist_template');

        ($this->as)($this->member)
            ->postJson("{$this->base}/checklist-templates", ['kind' => 'onboarding', 'name' => 'x', 'tasks' => [['title' => 'x', 'assignee_role' => 'hr', 'due_offset_days' => 0]]])
            ->assertForbidden();
    });
});
