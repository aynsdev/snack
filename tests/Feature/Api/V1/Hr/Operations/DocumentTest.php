<?php

use App\Enums\DocumentStatus;
use App\Enums\WorkspaceRole;
use App\Models\DocumentSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 09:00:00');
    Storage::fake('local');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->colleague = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->employee = $this->member->employeeIn($this->workspace);

    $this->base = "/api/v1/w/{$this->workspace->slug}/hr/documents";
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };
    $this->requestDocuments = fn (array $overrides = []) => ($this->as)($this->admin)->postJson($this->base, [
        'title' => 'Government ID',
        'description' => 'Front and back.',
        'allowed_formats' => ['pdf', 'jpg'],
        'max_size_mb' => 2,
        'employee_ids' => [$this->employee->id, $this->colleague->employeeIn($this->workspace)->id],
        ...$overrides,
    ]);
    $this->upload = fn (DocumentSubmission $submission, string $name = 'id.pdf', string $mime = 'application/pdf') => ($this->as)($this->member)
        ->post("{$this->base}/submissions/{$submission->id}/upload", ['file' => UploadedFile::fake()->create($name, 100, $mime)], ['Accept' => 'application/json']);
});

it('asks several people at once, and lists requests for HR and own documents for the person', function () {
    ($this->requestDocuments)()
        ->assertCreated()
        ->assertJsonPath('data.title', 'Government ID')
        ->assertJsonPath('data.total', 2)
        ->assertJsonCount(2, 'data.submissions');

    ($this->as)($this->member)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonCount(1, 'data.my_documents')
        ->assertJsonPath('data.my_documents.0.request.title', 'Government ID')
        ->assertJsonPath('data.my_documents.0.can.upload', true)
        ->assertJsonPath('data.requests', [])
        ->assertJsonPath('data.can_manage', false);

    ($this->as)($this->admin)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.requests.0.total', 2)
        ->assertJsonPath('data.can_manage', true);
});

it('keeps members from asking', function () {
    ($this->as)($this->member)
        ->postJson($this->base, ['title' => 'x', 'allowed_formats' => ['pdf'], 'max_size_mb' => 1, 'employee_ids' => [$this->employee->id]])
        ->assertForbidden();
});

it('lets the person upload, HR approve, and serves the file to owner and HR only', function () {
    ($this->requestDocuments)();
    $submission = $this->employee->documentSubmissions()->sole();

    ($this->upload)($submission, 'id.docx', 'application/msword')->assertUnprocessable()->assertJsonValidationErrors('file');

    ($this->upload)($submission)
        ->assertOk()
        ->assertJsonPath('data.status', DocumentStatus::Submitted->value)
        ->assertJsonPath('data.has_file', true);

    Storage::disk('local')->assertExists($submission->fresh()->file_path);

    ($this->as)($this->admin)
        ->postJson("{$this->base}/submissions/{$submission->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', DocumentStatus::Approved->value)
        ->assertJsonPath('data.reviewer', $this->admin->name);

    $url = "{$this->base}/submissions/{$submission->id}/file";
    ($this->as)($this->member)->get($url)->assertOk()->assertDownload('id.pdf');
    ($this->as)($this->admin)->get($url)->assertOk();
    ($this->as)($this->colleague)->getJson($url)->assertNotFound();
});

it('sends an upload back for another try', function () {
    ($this->requestDocuments)();
    $submission = $this->employee->documentSubmissions()->sole();
    ($this->upload)($submission, 'blurry.jpg', 'image/jpeg');

    ($this->as)($this->admin)
        ->postJson("{$this->base}/submissions/{$submission->id}/reject", ['note' => 'Too blurry'])
        ->assertOk()
        ->assertJsonPath('data.status', DocumentStatus::Rejected->value)
        ->assertJsonPath('data.review_note', 'Too blurry');

    ($this->as)($this->member)->postJson("{$this->base}/submissions/{$submission->id}/approve")->assertForbidden();
});

it('deletes a request with its files', function () {
    ($this->requestDocuments)();
    $request = $this->workspace->documentRequests()->sole();
    $submission = $this->employee->documentSubmissions()->sole();
    ($this->upload)($submission);
    $path = $submission->fresh()->file_path;

    ($this->as)($this->member)->deleteJson("{$this->base}/{$request->id}")->assertForbidden();
    ($this->as)($this->admin)->deleteJson("{$this->base}/{$request->id}")->assertNoContent();

    expect(DocumentSubmission::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});
