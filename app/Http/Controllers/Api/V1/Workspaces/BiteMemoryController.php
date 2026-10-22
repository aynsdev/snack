<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Actions\Bite\SaveBiteMemory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bite\StoreBiteMemoryRequest;
use App\Http\Requests\Bite\UpdateBiteMemoryRequest;
use App\Models\BiteMemory;
use App\Models\BiteSetting;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The facts an admin has told Bite — the mirror of
 * {@see \App\Http\Controllers\Workspaces\BiteMemoryController}. `{biteMemory}`
 * resolves through Workspace::biteMemories(), so another tenant's entry 404s.
 */
class BiteMemoryController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function store(Workspace $workspace, StoreBiteMemoryRequest $request, SaveBiteMemory $save): JsonResponse
    {
        $memory = $save->create(
            workspace: $this->context->workspace(),
            actor: $request->user(),
            attributes: $request->memory(),
        );

        return response()->json(['data' => self::present($memory->load('author:id,name'))], 201);
    }

    public function update(
        Workspace $workspace,
        BiteMemory $biteMemory,
        UpdateBiteMemoryRequest $request,
        SaveBiteMemory $save,
    ): JsonResponse {
        $memory = $save->update(
            memory: $biteMemory,
            actor: $request->user(),
            attributes: $request->changes(),
        );

        return response()->json(['data' => self::present($memory->load('author:id,name'))]);
    }

    public function destroy(
        Workspace $workspace,
        BiteMemory $biteMemory,
        Request $request,
        SaveBiteMemory $save,
    ): Response {
        $this->authorize('update', [BiteSetting::class, $workspace]);

        $save->delete($biteMemory, $request->user());

        return response()->noContent();
    }

    /**
     * One entry, in the shape the settings payload lists them.
     *
     * @return array{id: int, title: string, body: string, is_enabled: bool, author: string|null, updated_at: string|null}
     */
    public static function present(BiteMemory $memory): array
    {
        return [
            'id' => $memory->id,
            'title' => $memory->title,
            'body' => $memory->body,
            'is_enabled' => $memory->is_enabled,
            'author' => $memory->author?->name,
            'updated_at' => $memory->updated_at?->toIso8601String(),
        ];
    }
}
