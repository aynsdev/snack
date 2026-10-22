<?php

use App\Enums\AuditAction;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Guards cache the resolved user, so a token for somebody else needs
    // them forgotten first.
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->employee = $this->member->employeeIn($this->workspace);
    $this->base = "/api/v1/w/{$this->workspace->slug}/hr";
    $this->file = "{$this->base}/employees/{$this->employee->id}";
});

it('lists the employee directory for people managers', function () {
    ($this->as)($this->owner)
        ->getJson("{$this->base}/employees?search=".urlencode($this->member->name))
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->employee->id)
        ->assertJsonStructure(['data', 'links', 'meta', 'filters', 'departments', 'statuses']);
});

it('keeps the directory from ordinary members', function () {
    ($this->as)($this->member)
        ->getJson("{$this->base}/employees")
        ->assertForbidden();
});

it('returns your own file from me', function () {
    ($this->as)($this->member)
        ->getJson("{$this->base}/me")
        ->assertOk()
        ->assertJsonPath('data.employee.id', $this->employee->id)
        ->assertJsonPath('data.can_manage', false)
        ->assertJsonPath('data.records', []);
});

it('lets members see their own file but not somebody else\'s', function () {
    $colleague = joinWorkspace($this->workspace)->employeeIn($this->workspace);

    ($this->as)($this->member)->getJson($this->file)->assertOk();

    ($this->as)($this->member)
        ->getJson("{$this->base}/employees/{$colleague->id}")
        ->assertNotFound();
});

it('updates an employee and audits it', function () {
    ($this->as)($this->owner)
        ->patchJson($this->file, [
            'job_title' => 'Designer',
            'department' => 'Product',
            'employment_type' => 'full_time',
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('data.job_title', 'Designer');

    expect(AuditLog::query()->where('action', AuditAction::EmployeeUpdated)->exists())->toBeTrue();
});

it('refuses an update from the employee themselves', function () {
    ($this->as)($this->member)
        ->patchJson($this->file, ['employment_type' => 'full_time', 'status' => 'active'])
        ->assertForbidden();
});

it('records and removes employment history', function () {
    ($this->as)($this->owner)
        ->postJson("{$this->file}/history", [
            'change' => 'promoted',
            'effective_on' => '2026-10-01',
            'job_title' => 'Lead Designer',
            'apply_to_profile' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.change', 'promoted');

    expect($this->employee->refresh()->job_title)->toBe('Lead Designer');

    $entry = $this->employee->employmentHistories()->firstOrFail();

    ($this->as)($this->owner)
        ->deleteJson("{$this->file}/history/{$entry->id}")
        ->assertNoContent();
});

it('adds, updates, downloads and deletes a contract', function () {
    Storage::fake('local');
    Storage::fake('s3');

    $id = ($this->as)($this->owner)
        ->post("{$this->file}/contracts", [
            'title' => 'Employment contract',
            'employment_type' => 'full_time',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'file' => UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.has_file', true)
        ->json('data.id');

    ($this->as)($this->owner)
        ->patchJson("{$this->file}/contracts/{$id}", [
            'title' => 'Renewed contract',
            'employment_type' => 'full_time',
            'status' => 'active',
            'starts_on' => '2026-01-01',
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renewed contract');

    // The employee may download their own signed copy.
    ($this->as)($this->member)
        ->get("{$this->file}/contracts/{$id}/file")
        ->assertOk()
        ->assertDownload('contract.pdf');

    ($this->as)($this->owner)
        ->deleteJson("{$this->file}/contracts/{$id}")
        ->assertNoContent();

    expect($this->employee->contracts()->count())->toBe(0);
});

it('keeps HR records from the employee', function () {
    Storage::fake('local');
    Storage::fake('s3');

    $id = ($this->as)($this->owner)
        ->post("{$this->file}/records", [
            'type' => 'note',
            'title' => 'Check-in',
            'recorded_on' => '2026-10-01',
            'file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Check-in')
        ->json('data.id');

    ($this->as)($this->owner)
        ->get("{$this->file}/records/{$id}/file")
        ->assertOk();

    ($this->as)($this->member)
        ->get("{$this->file}/records/{$id}/file")
        ->assertForbidden();

    ($this->as)($this->owner)
        ->deleteJson("{$this->file}/records/{$id}")
        ->assertNoContent();
});

it('issues and removes a disciplinary action', function () {
    Storage::fake('local');
    Storage::fake('s3');

    $id = ($this->as)($this->owner)
        ->post("{$this->file}/disciplinary", [
            'type' => 'suspension',
            'description' => 'Repeated absences.',
            'starts_on' => '2026-10-01',
            'duration_days' => 3,
            'file' => UploadedFile::fake()->create('memo.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.duration_days', 3)
        ->json('data.id');

    ($this->as)($this->member)
        ->get("{$this->file}/disciplinary/{$id}/file")
        ->assertOk()
        ->assertDownload('memo.pdf');

    ($this->as)($this->owner)
        ->deleteJson("{$this->file}/disciplinary/{$id}")
        ->assertNoContent();
});

it('refuses a disciplinary action from an ordinary member', function () {
    $colleague = joinWorkspace($this->workspace)->employeeIn($this->workspace);

    ($this->as)($this->member)
        ->postJson("{$this->base}/employees/{$colleague->id}/disciplinary", [
            'type' => 'verbal_warning',
            'description' => 'Nope.',
            'starts_on' => '2026-10-01',
        ])
        ->assertForbidden();
});

it('records and removes an absence', function () {
    $id = ($this->as)($this->owner)
        ->postJson("{$this->file}/absences", ['date' => '2026-10-01', 'type' => 'sick'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'sick')
        ->json('data.id');

    ($this->as)($this->owner)
        ->postJson("{$this->file}/absences", ['date' => '2026-10-01', 'type' => 'sick'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    ($this->as)($this->owner)
        ->deleteJson("{$this->file}/absences/{$id}")
        ->assertNoContent();
});

it('404s a nested record that belongs to another employee', function () {
    $colleague = joinWorkspace($this->workspace)->employeeIn($this->workspace);
    $absence = $colleague->absences()->create([
        'workspace_id' => $this->workspace->id,
        'date' => '2026-10-01',
        'type' => 'sick',
    ]);

    ($this->as)($this->owner)
        ->deleteJson("{$this->file}/absences/{$absence->id}")
        ->assertNotFound();
});
