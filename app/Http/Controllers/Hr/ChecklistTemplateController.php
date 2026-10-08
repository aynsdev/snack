<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\ChecklistTemplateRequest;
use App\Models\ChecklistTemplate;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The onboarding and offboarding templates, managed from HR settings. Saving
 * one never touches checklists already started from it.
 */
class ChecklistTemplateController extends Controller
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function store(Workspace $workspace, ChecklistTemplateRequest $request): RedirectResponse
    {
        $template = DB::transaction(function () use ($workspace, $request): ChecklistTemplate {
            $template = $workspace->checklistTemplates()->create([
                'kind' => $request->validated('kind'),
                'name' => $request->validated('name'),
                'is_default' => false,
            ]);

            $this->save($template, $request);

            return $template;
        });

        $this->audit->handle($workspace, AuditAction::ChecklistTemplateSaved, $request->user(), $template, newValues: ['name' => $template->name]);

        return back()->with('success', "{$template->name} saved.");
    }

    public function update(Workspace $workspace, ChecklistTemplate $checklistTemplate, ChecklistTemplateRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($checklistTemplate, $request): void {
            $checklistTemplate->update(['name' => $request->validated('name')]);
            $checklistTemplate->tasks()->delete();

            $this->save($checklistTemplate, $request);
        });

        $this->audit->handle($workspace, AuditAction::ChecklistTemplateSaved, $request->user(), $checklistTemplate, newValues: ['name' => $checklistTemplate->name]);

        return back()->with('success', "{$checklistTemplate->name} saved.");
    }

    /**
     * Refused for the last template of its kind: resignations and automatic
     * onboarding need one to start from.
     */
    public function destroy(Workspace $workspace, ChecklistTemplate $checklistTemplate, Request $request): RedirectResponse
    {
        $this->authorize('delete', $checklistTemplate);

        if ($workspace->checklistTemplates()->ofKind($checklistTemplate->kind)->count() === 1) {
            return back()->with('error', "Keep at least one {$checklistTemplate->kind->label()} template.");
        }

        $checklistTemplate->delete();

        if ($checklistTemplate->is_default) {
            ChecklistTemplate::defaultFor($workspace, $checklistTemplate->kind)?->update(['is_default' => true]);
        }

        $this->audit->handle($workspace, AuditAction::ChecklistTemplateDeleted, $request->user(), $workspace, oldValues: ['name' => $checklistTemplate->name]);

        return back()->with('success', 'Template deleted.');
    }

    /**
     * Writes the steps in the order sent, and makes the template the default
     * for its kind when asked.
     */
    private function save(ChecklistTemplate $template, ChecklistTemplateRequest $request): void
    {
        foreach ((array) $request->validated('tasks') as $position => $task) {
            $template->tasks()->create([
                'title' => $task['title'],
                'description' => $task['description'] ?? null,
                'assignee_role' => $task['assignee_role'],
                'due_offset_days' => (int) $task['due_offset_days'],
                'position' => $position,
            ]);
        }

        if ($request->boolean('is_default')) {
            $template->workspace->checklistTemplates()
                ->ofKind($template->kind)
                ->whereKeyNot($template->id)
                ->update(['is_default' => false]);

            $template->update(['is_default' => true]);
        }
    }
}
