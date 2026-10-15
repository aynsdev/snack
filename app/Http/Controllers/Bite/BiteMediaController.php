<?php

namespace App\Http\Controllers\Bite;

use App\Enums\GenerationStatus;
use App\Enums\MediaKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\BiteGeneration;
use App\Models\Workspace;
use App\Services\BiteSummons;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Bite page's Media tab: every image and video Bite made for this person.
 *
 * Read from {@see BiteGeneration} rather than by searching attachments, because
 * only the generation knows the prompt and the model — the two things worth
 * showing beside a picture — and which ones are still being made.
 */
class BiteMediaController extends Controller
{
    private const PER_PAGE = 48;

    public function index(Workspace $workspace, Request $request, BiteSummons $summons): Response
    {
        $user = $request->user();

        abort_unless($summons->availableTo($workspace, $user), 404);

        $validated = $request->validate([
            'kind' => ['nullable', Rule::enum(MediaKind::class)],
        ]);

        $kind = isset($validated['kind']) ? MediaKind::from($validated['kind']) : null;

        $generations = BiteGeneration::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->when($kind, fn ($query) => $query->where('kind', $kind))
            /*
             * Finished-and-deleted rows are left out: their file went with the
             * chat it was posted in. In-progress rows stay, so a video that is
             * still rendering shows as a placeholder rather than not at all.
             */
            ->where(fn ($query) => $query
                ->whereIn('status', [GenerationStatus::Pending, GenerationStatus::Processing])
                ->orWhereNotNull('message_attachment_id'))
            ->with(['attachment', 'requestMessage.conversation'])
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('bite/media', [
            'kind' => $kind?->value,
            'generations' => [
                'data' => $generations->getCollection()->map(fn (BiteGeneration $generation): array => [
                    'id' => $generation->id,
                    'kind' => $generation->kind->value,
                    'model' => $generation->model->label(),
                    'prompt' => $generation->prompt,
                    'status' => $generation->status->value,
                    'created_at' => $generation->created_at?->toIso8601String(),
                    'attachment' => $generation->attachment
                        ? (new AttachmentResource($generation->attachment))->resolve($request)
                        : null,
                    'chat_id' => $generation->requestMessage?->conversation?->type->isBite()
                        ? $generation->requestMessage->conversation->public_id
                        : null,
                ])->all(),
                'next_page_url' => $generations->nextPageUrl(),
                'prev_page_url' => $generations->previousPageUrl(),
            ],
        ]);
    }
}
