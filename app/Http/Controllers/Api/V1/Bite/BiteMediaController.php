<?php

namespace App\Http\Controllers\Api\V1\Bite;

use App\Enums\MediaKind;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\BiteChats;
use App\Services\BiteSummons;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Every image and video Bite made for this person, newest first, a page at a
 * time (`?page=`), optionally only one `kind`.
 */
class BiteMediaController extends Controller
{
    public function index(Workspace $workspace, Request $request, BiteSummons $summons, BiteChats $chats): JsonResponse
    {
        $user = $request->user();

        abort_unless($summons->availableTo($workspace, $user), 404);

        $validated = $request->validate([
            'kind' => ['nullable', Rule::enum(MediaKind::class)],
        ]);

        $kind = isset($validated['kind']) ? MediaKind::from($validated['kind']) : null;

        return response()->json([
            'kind' => $kind?->value,
            ...$chats->media($workspace, $user, $kind, $request),
        ]);
    }
}
