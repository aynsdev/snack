<?php

namespace App\Http\Controllers\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\EquipmentCategoryRequest;
use App\Models\EquipmentCategory;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The kinds of equipment there are.
 */
class EquipmentCategoryController extends Controller
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function store(Workspace $workspace, EquipmentCategoryRequest $request): RedirectResponse
    {
        $category = $workspace->equipmentCategories()->create($request->validated());

        $this->audit->handle($workspace, AuditAction::EquipmentCategorySaved, $request->user(), $category, newValues: ['name' => $category->name]);

        return back()->with('success', "{$category->name} added.");
    }

    public function update(Workspace $workspace, EquipmentCategory $equipmentCategory, EquipmentCategoryRequest $request): RedirectResponse
    {
        $equipmentCategory->update($request->validated());

        $this->audit->handle($workspace, AuditAction::EquipmentCategorySaved, $request->user(), $equipmentCategory, newValues: ['name' => $equipmentCategory->name]);

        return back()->with('success', 'Category updated.');
    }

    /**
     * Only an empty category can go; equipment is never left without one.
     */
    public function destroy(Workspace $workspace, EquipmentCategory $equipmentCategory, Request $request): RedirectResponse
    {
        $this->authorize('delete', $equipmentCategory);

        if ($equipmentCategory->equipment()->exists()) {
            return back()->with('error', "{$equipmentCategory->name} still has equipment in it.");
        }

        $equipmentCategory->delete();

        $this->audit->handle($workspace, AuditAction::EquipmentCategoryDeleted, $request->user(), $workspace, oldValues: ['name' => $equipmentCategory->name]);

        return back()->with('success', 'Category deleted.');
    }
}
