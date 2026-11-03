<?php

use App\Enums\AuditAction;
use App\Enums\DisciplinaryActionType;
use App\Enums\DocumentStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\CompanyPolicy;
use App\Models\DocumentSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 09:00:00');
    Storage::fake('local');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->colleague = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->employee = $this->member->employeeIn($this->workspace);

    $this->base = "/w/{$this->workspace->slug}/hr";
});

describe('document requests', function () {
    beforeEach(function () {
        $this->requestDocuments = fn (array $overrides = []) => $this->actingAs($this->admin)->post("{$this->base}/documents", [
            'title' => 'Government ID',
            'description' => 'Front and back.',
            'allowed_formats' => ['pdf', 'jpg'],
            'max_size_mb' => 2,
            'employee_ids' => [$this->employee->id, $this->colleague->employeeIn($this->workspace)->id],
            ...$overrides,
        ]);
    });

    it('asks several people at once, each with their own copy', function () {
        ($this->requestDocuments)()->assertSessionHasNoErrors();

        $request = $this->workspace->documentRequests()->sole();

        expect($request->submissions)->toHaveCount(2)
            ->and($request->submissions->every(fn (DocumentSubmission $submission): bool => $submission->status === DocumentStatus::Pending))->toBeTrue()
            ->and(AuditLog::query()->where('action', AuditAction::DocumentRequested)->exists())->toBeTrue();
    });

    it('keeps members from asking, and refuses people from another workspace', function () {
        $this->actingAs($this->member)
            ->post("{$this->base}/documents", ['title' => 'x', 'allowed_formats' => ['pdf'], 'max_size_mb' => 1, 'employee_ids' => [$this->employee->id]])
            ->assertForbidden();

        [$other] = makeWorkspaceWithOwner();
        $stranger = joinWorkspace($other)->employeeIn($other);

        ($this->requestDocuments)(['employee_ids' => [$stranger->id]])->assertSessionHasErrors('employee_ids.0');
    });

    it('lets the person upload in an allowed format, and HR approve it', function () {
        ($this->requestDocuments)();
        $submission = $this->employee->documentSubmissions()->sole();

        $this->actingAs($this->member)
            ->post("{$this->base}/documents/submissions/{$submission->id}/upload", ['file' => UploadedFile::fake()->create('id.docx', 100)])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->member)
            ->post("{$this->base}/documents/submissions/{$submission->id}/upload", ['file' => UploadedFile::fake()->create('id.pdf', 3000, 'application/pdf')])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->member)
            ->post("{$this->base}/documents/submissions/{$submission->id}/upload", [
                'file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
                'expires_on' => '2030-01-01',
            ])
            ->assertSessionHasNoErrors();

        $submission->refresh();
        expect($submission->status)->toBe(DocumentStatus::Submitted)
            ->and($submission->expires_on->toDateString())->toBe('2030-01-01');
        Storage::disk('local')->assertExists($submission->file_path);

        $this->actingAs($this->member)->post("{$this->base}/documents/submissions/{$submission->id}/upload", [
            'file' => UploadedFile::fake()->create('again.pdf', 100, 'application/pdf'),
        ])->assertForbidden();

        $this->actingAs($this->admin)
            ->post("{$this->base}/documents/submissions/{$submission->id}/approve")
            ->assertSessionHasNoErrors();

        expect($submission->fresh()->status)->toBe(DocumentStatus::Approved)
            ->and(AuditLog::query()->where('action', AuditAction::DocumentApproved)->exists())->toBeTrue();
    });

    it('sends a rejected upload back for another try', function () {
        ($this->requestDocuments)();
        $submission = $this->employee->documentSubmissions()->sole();

        $this->actingAs($this->member)->post("{$this->base}/documents/submissions/{$submission->id}/upload", [
            'file' => UploadedFile::fake()->create('blurry.jpg', 100, 'image/jpeg'),
        ]);
        $first = $submission->fresh()->file_path;

        $this->actingAs($this->admin)->post("{$this->base}/documents/submissions/{$submission->id}/reject", ['note' => 'Too blurry']);
        expect($submission->fresh()->status)->toBe(DocumentStatus::Rejected);

        $this->actingAs($this->member)
            ->post("{$this->base}/documents/submissions/{$submission->id}/upload", ['file' => UploadedFile::fake()->create('sharp.jpg', 100, 'image/jpeg')])
            ->assertSessionHasNoErrors();

        expect($submission->fresh()->status)->toBe(DocumentStatus::Submitted)
            ->and($submission->fresh()->review_note)->toBeNull();
        Storage::disk('local')->assertMissing($first);
    });

    it('serves a document to its owner and HR only', function () {
        ($this->requestDocuments)();
        $submission = $this->employee->documentSubmissions()->sole();
        $this->actingAs($this->member)->post("{$this->base}/documents/submissions/{$submission->id}/upload", [
            'file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        ]);

        $url = "{$this->base}/documents/submissions/{$submission->id}/file";

        $this->actingAs($this->member)->get($url)->assertOk();
        $this->actingAs($this->admin)->get($url)->assertOk();
        $this->actingAs($this->colleague)->get($url)->assertNotFound();
    });

    it('shows people their own documents and HR every request', function () {
        ($this->requestDocuments)();

        $this->actingAs($this->member)
            ->get("{$this->base}/documents")
            ->assertInertia(fn (Assert $page) => $page
                ->component('hr/documents')
                ->has('my_documents', 1)
                ->where('my_documents.0.request.title', 'Government ID')
                ->where('my_documents.0.can.upload', true)
                ->where('requests', [])
                ->where('can_manage', false));

        $this->actingAs($this->admin)
            ->get("{$this->base}/documents")
            ->assertInertia(fn (Assert $page) => $page
                ->where('requests.0.total', 2)
                ->where('requests.0.uploaded', 0));
    });

    it('deletes a request with its files', function () {
        ($this->requestDocuments)();
        $request = $this->workspace->documentRequests()->sole();
        $submission = $this->employee->documentSubmissions()->sole();
        $this->actingAs($this->member)->post("{$this->base}/documents/submissions/{$submission->id}/upload", [
            'file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        ]);
        $path = $submission->fresh()->file_path;

        $this->actingAs($this->admin)->delete("{$this->base}/documents/{$request->id}")->assertSessionHasNoErrors();

        expect(DocumentSubmission::query()->count())->toBe(0);
        Storage::disk('local')->assertMissing($path);
    });
});

describe('disciplinary actions', function () {
    it('lets HR issue one, which the employee sees on their own file', function () {
        $this->actingAs($this->admin)
            ->post("{$this->base}/employees/{$this->employee->id}/disciplinary", [
                'type' => DisciplinaryActionType::Suspension->value,
                'description' => 'Repeated no-shows.',
                'starts_on' => '2026-10-01',
                'duration_days' => 3,
                'file' => UploadedFile::fake()->create('letter.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $action = $this->employee->disciplinaryActions()->sole();
        expect($action->endsOn()->toDateString())->toBe('2026-10-03')
            ->and(AuditLog::query()->where('action', AuditAction::DisciplinaryActionIssued)->exists())->toBeTrue();

        $this->actingAs($this->member)
            ->get("{$this->base}/employees/{$this->employee->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('disciplinary_actions', 1)
                ->where('disciplinary_actions.0.type_label', 'Suspension')
                ->where('disciplinary_actions.0.ends_on', '2026-10-03'));

        $this->actingAs($this->member)->get("{$this->base}/employees/{$this->employee->id}/disciplinary/{$action->id}/file")->assertOk();
        $this->actingAs($this->colleague)->get("{$this->base}/employees/{$this->employee->id}/disciplinary/{$action->id}/file")->assertNotFound();
    });

    it('needs a duration for a suspension only, and drops one given otherwise', function () {
        $this->actingAs($this->admin)
            ->post("{$this->base}/employees/{$this->employee->id}/disciplinary", [
                'type' => 'suspension', 'description' => 'x', 'starts_on' => '2026-10-01',
            ])
            ->assertSessionHasErrors('duration_days');

        $this->actingAs($this->admin)->post("{$this->base}/employees/{$this->employee->id}/disciplinary", [
            'type' => 'written_warning', 'description' => 'Late again.', 'starts_on' => '2026-10-01', 'duration_days' => 5,
        ]);

        expect($this->employee->disciplinaryActions()->sole()->duration_days)->toBeNull();
    });

    it('keeps members from issuing or removing one', function () {
        $action = $this->employee->disciplinaryActions()->create([
            'workspace_id' => $this->workspace->id, 'type' => 'verbal_warning', 'description' => 'x', 'starts_on' => '2026-09-01',
        ]);

        $this->actingAs($this->member)
            ->post("{$this->base}/employees/{$this->employee->id}/disciplinary", ['type' => 'verbal_warning', 'description' => 'x', 'starts_on' => '2026-10-01'])
            ->assertForbidden();
        $this->actingAs($this->member)->delete("{$this->base}/employees/{$this->employee->id}/disciplinary/{$action->id}")->assertForbidden();
        $this->actingAs($this->admin)->delete("{$this->base}/employees/{$this->employee->id}/disciplinary/{$action->id}")->assertSessionHasNoErrors();

        expect($this->employee->disciplinaryActions()->count())->toBe(0);
    });
});

describe('company policies', function () {
    beforeEach(function () {
        $this->actingAs($this->admin)->post("{$this->base}/policies", [
            'title' => 'Code of conduct',
            'body' => 'Be kind.',
            'requires_acknowledgement' => true,
            'file' => UploadedFile::fake()->create('conduct.pdf', 100, 'application/pdf'),
        ]);
        $this->policy = CompanyPolicy::query()->sole();
    });

    it('lets everybody read and acknowledge, and HR see progress', function () {
        $this->actingAs($this->member)->post("{$this->base}/policies/{$this->policy->id}/acknowledge")->assertSessionHasNoErrors();
        $this->actingAs($this->member)->post("{$this->base}/policies/{$this->policy->id}/acknowledge")->assertSessionHasNoErrors();

        $this->actingAs($this->member)
            ->get("{$this->base}/policies")
            ->assertInertia(fn (Assert $page) => $page
                ->component('hr/policies')
                ->where('policies.0.acknowledged', true)
                ->where('policies.0.acknowledged_count', null)
                ->where('can_manage', false));

        $this->actingAs($this->admin)
            ->get("{$this->base}/policies")
            ->assertInertia(fn (Assert $page) => $page
                ->where('policies.0.acknowledged', false)
                ->where('policies.0.acknowledged_count', 1)
                ->where('headcount', 4));

        $this->actingAs($this->colleague)->get("{$this->base}/policies/{$this->policy->id}/file")->assertOk();
    });

    it('asks everybody again when the text changes, but not for a new title', function () {
        $this->actingAs($this->member)->post("{$this->base}/policies/{$this->policy->id}/acknowledge");

        $this->actingAs($this->admin)->post("{$this->base}/policies/{$this->policy->id}", [
            'title' => 'Code of Conduct', 'body' => 'Be kind.', 'requires_acknowledgement' => true,
        ]);
        expect($this->policy->acknowledgements()->count())->toBe(1);

        $this->actingAs($this->admin)->post("{$this->base}/policies/{$this->policy->id}", [
            'title' => 'Code of Conduct', 'body' => 'Be kind, and on time.', 'requires_acknowledgement' => true,
        ]);
        expect($this->policy->acknowledgements()->count())->toBe(0);
    });

    it('keeps members from writing policies, and refuses acknowledging one that needs none', function () {
        $this->actingAs($this->member)->post("{$this->base}/policies", ['title' => 'Mine', 'body' => 'x'])->assertForbidden();
        $this->actingAs($this->member)->delete("{$this->base}/policies/{$this->policy->id}")->assertForbidden();

        $this->policy->update(['requires_acknowledgement' => false]);
        $this->actingAs($this->member)->post("{$this->base}/policies/{$this->policy->id}/acknowledge")->assertForbidden();
    });

    it('keeps another workspace out', function () {
        $this->actingAs(User::factory()->create())->get("{$this->base}/policies")->assertNotFound();
    });
});

describe('org chart', function () {
    it('lists everybody employed with their manager', function () {
        $adminFile = $this->admin->employeeIn($this->workspace);
        $this->employee->update(['manager_id' => $adminFile->id, 'job_title' => 'Engineer']);

        $this->actingAs($this->member)
            ->get("{$this->base}/org-chart")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('hr/org-chart')
                ->has('people', 4)
                ->where('own_employee_id', $this->employee->id)
                ->where('people', fn ($people) => collect($people)->contains(fn (array $person): bool => $person['id'] === $this->employee->id
                    && $person['manager_id'] === $adminFile->id
                    && $person['job_title'] === 'Engineer'
                    && $person['status'] === 'active')));
    });
});

it('counts uploaded documents on the HR dashboard', function () {
    $this->actingAs($this->admin)->post("{$this->base}/documents", [
        'title' => 'ID', 'allowed_formats' => ['pdf'], 'max_size_mb' => 2, 'employee_ids' => [$this->employee->id],
    ]);
    $submission = $this->employee->documentSubmissions()->sole();
    $this->actingAs($this->member)->post("{$this->base}/documents/submissions/{$submission->id}/upload", [
        'file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
    ]);

    $this->actingAs($this->admin)
        ->get("{$this->base}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->where('pending_requests.documents', 1)
            ->where('kpis.pending_approvals', 1));
});
