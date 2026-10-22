<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Hr\NotifyDocuments;
use App\Actions\Hr\StoreHrFile;
use App\Enums\AuditAction;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ReviewDocumentRequest;
use App\Http\Requests\Hr\StoreDocumentRequestRequest;
use App\Http\Requests\Hr\UploadDocumentRequest;
use App\Http\Resources\Hr\DocumentRequestResource;
use App\Http\Resources\Hr\DocumentSubmissionResource;
use App\Models\DocumentRequest;
use App\Models\DocumentSubmission;
use App\Models\Employee;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Document requests for the mobile app: HR asking for paperwork, each person
 * uploading their own copy, and HR approving or sending it back.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\DocumentController}. The
 * file itself is served by the web controller's `download`, which the API
 * routes to directly — it only streams.
 */
class DocumentController extends Controller
{
    /** What a submission needs loaded to be rendered on its own. */
    private const SUBMISSION = ['documentRequest.workspace', 'employee.user', 'reviewer'];

    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly NotifyDocuments $notify,
        private readonly StoreHrFile $files,
    ) {}

    /**
     * The viewer's own documents, and — for HR — every request with
     * everybody's answer to it and the people who could be asked.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('view', $workspace);

        $user = $request->user();
        $employee = $user->employeeIn($workspace);
        $canManage = $user->can('create', [DocumentRequest::class, $workspace]);

        return response()->json(['data' => [
            'my_documents' => $employee === null ? [] : DocumentSubmissionResource::collection(
                $employee->documentSubmissions()
                    ->with(['documentRequest.workspace', 'employee', 'reviewer'])
                    ->latest()
                    ->get(),
            )->resolve($request),
            'requests' => $canManage ? DocumentRequestResource::collection(
                $workspace->documentRequests()
                    ->with(['creator', 'submissions.employee.user', 'submissions.reviewer', 'submissions.documentRequest.workspace'])
                    ->latest()
                    ->get(),
            )->resolve($request) : [],
            'employees' => $canManage ? $workspace->employees()
                ->employed()
                ->with('user')
                ->get()
                ->map(fn (Employee $person): array => [
                    'id' => $person->id,
                    'name' => $person->user->name,
                    'department' => $person->department,
                ])
                ->sortBy('name')
                ->values()
                ->all() : [],
            'formats' => DocumentRequest::FORMATS,
            'can_manage' => $canManage,
        ]]);
    }

    public function store(Workspace $workspace, StoreDocumentRequestRequest $request): JsonResponse
    {
        $employees = $workspace->employees()->with('user')->findMany($request->validated('employee_ids'));

        $documentRequest = DB::transaction(function () use ($workspace, $request, $employees): DocumentRequest {
            $documentRequest = $workspace->documentRequests()->create([
                ...$request->safe()->only(['title', 'description', 'allowed_formats', 'max_size_mb', 'due_on']),
                'created_by' => $request->user()->id,
            ]);

            $documentRequest->submissions()->createMany(
                $employees->map(fn (Employee $employee): array => [
                    'employee_id' => $employee->id,
                    'status' => DocumentStatus::Pending,
                ])->all(),
            );

            return $documentRequest;
        });

        $this->audit->handle($workspace, AuditAction::DocumentRequested, $request->user(), $documentRequest, newValues: [
            'title' => $documentRequest->title,
            'employees' => $employees->count(),
        ]);

        $this->notify->requested($documentRequest, $employees);

        return (new DocumentRequestResource($documentRequest->fresh([
            'creator', 'submissions.employee.user', 'submissions.reviewer', 'submissions.documentRequest.workspace',
        ])))->response()->setStatusCode(201);
    }

    /**
     * Withdraws the request, and with it every file uploaded for it.
     */
    public function destroy(Workspace $workspace, DocumentRequest $documentRequest, Request $request): Response
    {
        $this->authorize('delete', $documentRequest);

        $paths = $documentRequest->submissions()->whereNotNull('file_path')->pluck('file_path');

        $documentRequest->delete();
        $paths->each(fn (string $path) => $this->files->delete($path));

        $this->audit->handle($workspace, AuditAction::DocumentRequestDeleted, $request->user(), $workspace, oldValues: ['title' => $documentRequest->title]);

        return response()->noContent();
    }

    /**
     * Uploading — or, after a rejection, uploading again. Multipart.
     */
    public function upload(Workspace $workspace, DocumentSubmission $documentSubmission, UploadDocumentRequest $request): DocumentSubmissionResource
    {
        $this->files->delete($documentSubmission->file_path);

        $documentSubmission->update([
            ...$this->files->handle($documentSubmission->employee, $request->file('file'), 'documents'),
            'status' => DocumentStatus::Submitted,
            'expires_on' => $request->validated('expires_on'),
            'submitted_at' => now(),
            'reviewer_id' => null,
            'reviewed_at' => null,
            'review_note' => null,
        ]);

        $this->notify->submitted($documentSubmission);

        return new DocumentSubmissionResource($documentSubmission->fresh(self::SUBMISSION));
    }

    public function approve(Workspace $workspace, DocumentSubmission $documentSubmission, ReviewDocumentRequest $request): DocumentSubmissionResource
    {
        return $this->decide($workspace, $documentSubmission, $request, DocumentStatus::Approved);
    }

    public function reject(Workspace $workspace, DocumentSubmission $documentSubmission, ReviewDocumentRequest $request): DocumentSubmissionResource
    {
        return $this->decide($workspace, $documentSubmission, $request, DocumentStatus::Rejected);
    }

    private function decide(Workspace $workspace, DocumentSubmission $submission, ReviewDocumentRequest $request, DocumentStatus $status): DocumentSubmissionResource
    {
        $submission->update([
            'status' => $status,
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $request->validated('note'),
        ]);

        $this->audit->handle(
            $workspace,
            $status === DocumentStatus::Approved ? AuditAction::DocumentApproved : AuditAction::DocumentRejected,
            $request->user(),
            $submission,
            newValues: ['employee' => $submission->employee->user->name, 'document' => $submission->documentRequest->title],
        );

        $this->notify->decided($submission);

        return new DocumentSubmissionResource($submission->fresh(self::SUBMISSION));
    }
}
