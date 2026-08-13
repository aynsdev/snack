<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Bite\SaveBiteMemory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bite\StoreBiteMemoryRequest;
use App\Http\Requests\Bite\UpdateBiteMemoryRequest;
use App\Models\BiteMemory;
use App\Models\BiteSetting;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The facts an admin has told Bite.
 *
 * Its own controller rather than a section of {@see BiteController}, because
 * these are rows with their own lifecycle: the settings screen is one form saved
 * whole, and this is a list things are added to and removed from one at a time.
 *
 * The route parameter is `{biteMemory}` rather than `{memory}` so that
 * `scopeBindings()` resolves it through `Workspace::biteMemories()` — an entry
 * belonging to another tenant then 404s at the router, before any policy runs.
 * Authorization is checked again regardless, against the workspace rather than
 * the row: there is one right here, and it is "may this person configure the
 * assistant".
 */
class BiteMemoryController extends Controller
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function store(
        Workspace $workspace,
        StoreBiteMemoryRequest $request,
        SaveBiteMemory $save,
    ): RedirectResponse {
        $save->create(
            workspace: $this->context->workspace(),
            actor: $request->user(),
            attributes: $request->memory(),
        );

        return back()->with('success', 'Added to what Bite knows.');
    }

    public function update(
        Workspace $workspace,
        BiteMemory $biteMemory,
        UpdateBiteMemoryRequest $request,
        SaveBiteMemory $save,
    ): RedirectResponse {
        $save->update(
            memory: $biteMemory,
            actor: $request->user(),
            attributes: $request->changes(),
        );

        return back()->with('success', 'Saved.');
    }

    public function destroy(
        Workspace $workspace,
        BiteMemory $biteMemory,
        Request $request,
        SaveBiteMemory $save,
    ): RedirectResponse {
        $this->authorize('update', [BiteSetting::class, $workspace]);

        $save->delete($biteMemory, $request->user());

        return back()->with('success', 'Removed from what Bite knows.');
    }
}
