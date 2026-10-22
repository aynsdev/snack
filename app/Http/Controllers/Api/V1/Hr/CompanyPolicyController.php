<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Hr\StoreHrFile;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\CompanyPolicyRequest;
use App\Http\Resources\Hr\CompanyPolicyResource;
use App\Models\CompanyPolicy;
use App\Models\Employee;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The company handbook for the mobile app.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\CompanyPolicyController}.
 * Updating stays a POST, as on the web, because it is multipart (an attached
 * file). The file itself is served by the web controller's `download`, which
 * the API routes to directly.
 */
class CompanyPolicyController extends Controller
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly StoreHrFile $files,
    ) {}

    /**
     * Every policy, with whether the viewer has acknowledged it; HR also gets
     * each policy's acknowledgement count and the headcount it is out of.
     */
    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        $this->authorize('viewAny', [CompanyPolicy::class, $workspace]);

        $canManage = $request->user()->can('create', [CompanyPolicy::class, $workspace]);

        $policies = $this->query($workspace, $request)
            ->when($canManage, fn ($query) => $query->withCount('acknowledgements'))
            ->orderBy('title')
            ->get();

        return response()->json(['data' => [
            'policies' => CompanyPolicyResource::collection($policies)->resolve($request),
            'headcount' => $canManage ? $workspace->employees()->employed()->count() : null,
            'can_manage' => $canManage,
        ]]);
    }

    public function store(Workspace $workspace, CompanyPolicyRequest $request): JsonResponse
    {
        $policy = $workspace->companyPolicies()->create([
            ...$request->safe()->only(['title', 'body']),
            ...($request->hasFile('file') ? $this->files->forWorkspace($workspace, $request->file('file'), 'policies') : []),
            'requires_acknowledgement' => $request->boolean('requires_acknowledgement'),
            'author_id' => $request->user()->id,
        ]);

        $this->audit->handle($workspace, AuditAction::CompanyPolicyPublished, $request->user(), $policy, newValues: ['title' => $policy->title]);

        return (new CompanyPolicyResource($this->present($workspace, $policy, $request)))->response()->setStatusCode(201);
    }

    /**
     * Changing what a policy says asks everybody to read it again: earlier
     * acknowledgements were of the old text.
     */
    public function update(Workspace $workspace, CompanyPolicy $companyPolicy, CompanyPolicyRequest $request): CompanyPolicyResource
    {
        $attributes = [
            ...$request->safe()->only(['title', 'body']),
            'requires_acknowledgement' => $request->boolean('requires_acknowledgement'),
        ];

        if ($request->hasFile('file') || $request->boolean('remove_file')) {
            $this->files->delete($companyPolicy->file_path);
            $attributes = [
                ...$attributes,
                ...($request->hasFile('file')
                    ? $this->files->forWorkspace($workspace, $request->file('file'), 'policies')
                    : ['file_path' => null, 'file_name' => null]),
            ];
        }

        $companyPolicy->fill($attributes);
        $reworded = $companyPolicy->isDirty('body') || $companyPolicy->isDirty('file_path');
        $companyPolicy->save();

        if ($reworded) {
            $companyPolicy->acknowledgements()->delete();
        }

        $this->audit->handle($workspace, AuditAction::CompanyPolicyUpdated, $request->user(), $companyPolicy, newValues: [
            'title' => $companyPolicy->title,
            'acknowledgements_reset' => $reworded,
        ]);

        return new CompanyPolicyResource($this->present($workspace, $companyPolicy, $request));
    }

    public function destroy(Workspace $workspace, CompanyPolicy $companyPolicy, Request $request): Response
    {
        $this->authorize('delete', $companyPolicy);

        $companyPolicy->delete();
        $this->files->delete($companyPolicy->file_path);

        $this->audit->handle($workspace, AuditAction::CompanyPolicyDeleted, $request->user(), $workspace, oldValues: ['title' => $companyPolicy->title]);

        return response()->noContent();
    }

    /**
     * Idempotent: acknowledging twice keeps the first acknowledgement.
     */
    public function acknowledge(Workspace $workspace, CompanyPolicy $companyPolicy, Request $request): CompanyPolicyResource
    {
        $this->authorize('acknowledge', $companyPolicy);

        $companyPolicy->acknowledgements()->firstOrCreate(
            ['employee_id' => $request->user()->employeeIn($workspace)->id],
            ['acknowledged_at' => now()],
        );

        return new CompanyPolicyResource($this->present($workspace, $companyPolicy, $request));
    }

    /**
     * Policies with the viewer's own acknowledgement flagged.
     *
     * @return HasMany<CompanyPolicy, Workspace>
     */
    private function query(Workspace $workspace, Request $request): HasMany
    {
        $employee = $request->user()->employeeIn($workspace);

        return $workspace->companyPolicies()
            ->with('author', 'workspace')
            ->withExists(['acknowledgements as acknowledged' => fn ($query) => $query->where('employee_id', $employee instanceof Employee ? $employee->id : 0)]);
    }

    /**
     * One policy re-read the way the list renders it.
     */
    private function present(Workspace $workspace, CompanyPolicy $policy, Request $request): CompanyPolicy
    {
        $canManage = $request->user()->can('create', [CompanyPolicy::class, $workspace]);

        return $this->query($workspace, $request)
            ->when($canManage, fn ($query) => $query->withCount('acknowledgements'))
            ->findOrFail($policy->id);
    }
}
