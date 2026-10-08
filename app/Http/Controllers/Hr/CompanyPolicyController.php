<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Hr\StoreHrFile;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\CompanyPolicyRequest;
use App\Http\Resources\Hr\CompanyPolicyResource;
use App\Models\CompanyPolicy;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The company handbook: policies everybody reads, some of which they have to
 * confirm they have read.
 */
class CompanyPolicyController extends Controller
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly StoreHrFile $files,
    ) {}

    public function index(Workspace $workspace, Request $request): Response
    {
        $this->authorize('viewAny', [CompanyPolicy::class, $workspace]);

        $user = $request->user();
        $employee = $user->employeeIn($workspace);
        $canManage = $user->can('create', [CompanyPolicy::class, $workspace]);

        $policies = $workspace->companyPolicies()
            ->with('author', 'workspace')
            ->withExists(['acknowledgements as acknowledged' => fn ($query) => $query->where('employee_id', $employee->id ?? 0)])
            ->when($canManage, fn ($query) => $query->withCount('acknowledgements'))
            ->orderBy('title')
            ->get();

        return Inertia::render('hr/policies', [
            'policies' => CompanyPolicyResource::collection($policies)->resolve($request),
            'headcount' => $canManage ? $workspace->employees()->employed()->count() : null,
            'can_manage' => $canManage,
        ]);
    }

    public function store(Workspace $workspace, CompanyPolicyRequest $request): RedirectResponse
    {
        $policy = $workspace->companyPolicies()->create([
            ...$request->safe()->only(['title', 'body']),
            ...($request->hasFile('file') ? $this->files->forWorkspace($workspace, $request->file('file'), 'policies') : []),
            'requires_acknowledgement' => $request->boolean('requires_acknowledgement'),
            'author_id' => $request->user()->id,
        ]);

        $this->audit->handle($workspace, AuditAction::CompanyPolicyPublished, $request->user(), $policy, newValues: ['title' => $policy->title]);

        return back()->with('success', "{$policy->title} published.");
    }

    /**
     * Changing what a policy says asks everybody to read it again: earlier
     * acknowledgements were of the old text.
     */
    public function update(Workspace $workspace, CompanyPolicy $companyPolicy, CompanyPolicyRequest $request): RedirectResponse
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

        return back()->with('success', $reworded && $companyPolicy->requires_acknowledgement
            ? 'Policy updated. Everybody will be asked to acknowledge it again.'
            : 'Policy updated.');
    }

    public function destroy(Workspace $workspace, CompanyPolicy $companyPolicy, Request $request): RedirectResponse
    {
        $this->authorize('delete', $companyPolicy);

        $companyPolicy->delete();
        $this->files->delete($companyPolicy->file_path);

        $this->audit->handle($workspace, AuditAction::CompanyPolicyDeleted, $request->user(), $workspace, oldValues: ['title' => $companyPolicy->title]);

        return back()->with('success', 'Policy deleted.');
    }

    public function acknowledge(Workspace $workspace, CompanyPolicy $companyPolicy, Request $request): RedirectResponse
    {
        $this->authorize('acknowledge', $companyPolicy);

        $companyPolicy->acknowledgements()->firstOrCreate(
            ['employee_id' => $request->user()->employeeIn($workspace)->id],
            ['acknowledged_at' => now()],
        );

        return back()->with('success', "Thanks — you've acknowledged {$companyPolicy->title}.");
    }

    public function download(Workspace $workspace, CompanyPolicy $companyPolicy): StreamedResponse
    {
        $this->authorize('view', $companyPolicy);

        abort_if($companyPolicy->file_path === null, 404);

        return Storage::disk(StoreHrFile::disk())->download($companyPolicy->file_path, $companyPolicy->file_name);
    }
}
