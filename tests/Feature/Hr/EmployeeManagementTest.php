<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->employee = $this->member->employeeIn($this->workspace);
    $this->base = "/w/{$this->workspace->slug}/hr";
});

it('lets an admin browse the employee directory', function () {
    $this->actingAs($this->owner)
        ->get("{$this->base}/employees")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/employees/index')
            ->has('employees.data', 2)
            ->has('employees.data.0.user.name'));
});

it('keeps the directory from ordinary members', function () {
    $this->actingAs($this->member)
        ->get("{$this->base}/employees")
        ->assertForbidden();
});

it('lets members see their own file but not somebody else\'s', function () {
    $colleague = joinWorkspace($this->workspace)->employeeIn($this->workspace);

    $this->actingAs($this->member)
        ->get("{$this->base}/employees/{$this->employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/employees/show')
            ->where('employee.user.name', $this->member->name)
            ->where('can_manage', false)
            ->where('records', []));

    $this->actingAs($this->member)
        ->get("{$this->base}/employees/{$colleague->id}")
        ->assertNotFound();
});

it('redirects "me" to your own file', function () {
    $this->actingAs($this->member)
        ->get("{$this->base}/me")
        ->assertRedirect("{$this->base}/employees/{$this->employee->id}");
});

it('lets an admin update an employee and audits it', function () {
    $this->actingAs($this->owner)
        ->patch("{$this->base}/employees/{$this->employee->id}", [
            'employee_number' => 'E-001',
            'job_title' => 'Senior Engineer',
            'department' => 'Engineering',
            'employment_type' => 'full_time',
            'status' => 'active',
            'phone' => '+63 900 000 0000',
        ])
        ->assertRedirect();

    expect($this->employee->fresh())
        ->job_title->toBe('Senior Engineer')
        ->employee_number->toBe('E-001');

    expect(AuditLog::query()->where('action', AuditAction::EmployeeUpdated)->exists())->toBeTrue();
});

it('refuses a member editing their own file', function () {
    $this->actingAs($this->member)
        ->patch("{$this->base}/employees/{$this->employee->id}", [
            'employment_type' => 'full_time',
            'status' => 'active',
            'job_title' => 'CEO',
        ])
        ->assertForbidden();
});

it('records a promotion and applies it to the profile', function () {
    $this->actingAs($this->owner)
        ->post("{$this->base}/employees/{$this->employee->id}/history", [
            'change' => 'promoted',
            'effective_on' => '2026-09-01',
            'job_title' => 'Lead',
            'department' => 'Platform',
            'apply_to_profile' => true,
        ])
        ->assertRedirect();

    expect($this->employee->fresh()->job_title)->toBe('Lead')
        ->and($this->employee->employmentHistories()->count())->toBe(2);
});

it('stores a contract with its file on the private disk and serves it to the employee', function () {
    Storage::fake('local');

    $this->actingAs($this->owner)
        ->post("{$this->base}/employees/{$this->employee->id}/contracts", [
            'title' => 'Employment agreement',
            'employment_type' => 'full_time',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'salary_amount' => 50000,
            'salary_currency' => 'PHP',
            'pay_frequency' => 'monthly',
            'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $contract = $this->employee->contracts()->firstOrFail();

    Storage::disk('local')->assertExists($contract->file_path);

    $this->actingAs($this->member)
        ->get("{$this->base}/employees/{$this->employee->id}/contracts/{$contract->id}/file")
        ->assertOk();
});

it('files records that only HR can download', function () {
    Storage::fake('local');

    $this->actingAs($this->owner)
        ->post("{$this->base}/employees/{$this->employee->id}/records", [
            'type' => 'warning',
            'title' => 'Late to standup',
            'recorded_on' => '2026-09-10',
            'file' => UploadedFile::fake()->create('memo.pdf', 10, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    $record = $this->employee->records()->firstOrFail();

    $this->actingAs($this->member)
        ->get("{$this->base}/employees/{$this->employee->id}/records/{$record->id}/file")
        ->assertForbidden();
});

it('records an absence once per day', function () {
    $payload = ['date' => '2026-09-15', 'type' => 'unexcused', 'note' => 'No show'];

    $this->actingAs($this->owner)
        ->post("{$this->base}/employees/{$this->employee->id}/absences", $payload)
        ->assertSessionHasNoErrors();

    $this->actingAs($this->owner)
        ->post("{$this->base}/employees/{$this->employee->id}/absences", $payload)
        ->assertSessionHasErrors('date');

    expect($this->employee->absences()->count())->toBe(1);
});

it('scopes nested records to the employee in the path', function () {
    $colleague = joinWorkspace($this->workspace)->employeeIn($this->workspace);
    $absence = $colleague->absences()->create([
        'workspace_id' => $this->workspace->id,
        'date' => '2026-09-15',
        'type' => 'sick',
    ]);

    $this->actingAs($this->owner)
        ->delete("{$this->base}/employees/{$this->employee->id}/absences/{$absence->id}")
        ->assertNotFound();
});

it('refuses an employee from another workspace', function () {
    [$other] = makeWorkspaceWithOwner();
    $stranger = joinWorkspace($other)->employeeIn($other);

    $this->actingAs($this->owner)
        ->get("{$this->base}/employees/{$stranger->id}")
        ->assertNotFound();
});
