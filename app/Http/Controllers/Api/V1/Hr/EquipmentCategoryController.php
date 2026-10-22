<?php

namespace App\Http\Controllers\Api\V1\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\EquipmentCategoryRequest;
use App\Models\EquipmentCategory;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The kinds of equipment there are, for the mobile app.
 *
 * The JSON mirror of {@see \App\Http\Controllers\Hr\EquipmentCategoryController}.
 * A category is returned in the same shape the inventory lists it in.
 */
class EquipmentCategoryController extends Controller
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    public function store(Workspace $workspace, EquipmentCategoryRequest $request): JsonResponse
    {
        $category = $workspace->equipmentCategories()->create($request->validated());

        $this->audit->handle($workspace, AuditAction::EquipmentCategorySaved, $request->user(), $category, newValues: ['name' => $category->name]);

        return response()->json(['data' => $this->present($category)], 201);
    }

    public function update(Workspace $workspace, EquipmentCategory $equipmentCategory, EquipmentCategoryRequest $request): JsonResponse
    {
        $equipmentCategory->update($request->validated());

        $this->audit->handle($workspace, AuditAction::EquipmentCategorySaved, $request->user(), $equipmentCategory, newValues: ['name' => $equipmentCategory->name]);

        return response()->json(['data' => $this->present($equipmentCategory)]);
    }

    /**
     * Only an empty category can go — a 422 otherwise; equipment is never
     * left without one.
     */
    public function destroy(Workspace $workspace, EquipmentCategory $equipmentCategory, Request $request): Response
    {
        $this->authorize('delete', $equipmentCategory);

        if ($equipmentCategory->equipment()->exists()) {
            throw ValidationException::withMessages([
                'equipment_category' => "{$equipmentCategory->name} still has equipment in it.",
            ]);
        }

        $equipmentCategory->delete();

        $this->audit->handle($workspace, AuditAction::EquipmentCategoryDeleted, $request->user(), $workspace, oldValues: ['name' => $equipmentCategory->name]);

        return response()->noContent();
    }

    /**
     * @return array{id: int, name: string, description: ?string, count: int}
     */
    private function present(EquipmentCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'count' => $category->equipment()->count(),
        ];
    }
}
