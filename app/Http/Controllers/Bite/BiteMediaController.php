<?php

namespace App\Http\Controllers\Bite;

use App\Enums\MediaKind;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\BiteChats;
use App\Services\BiteSummons;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Bite page's Media tab: every image and video Bite made for this person.
 *
 * The listing itself lives in {@see BiteChats::media()}, shared with the mobile
 * API.
 */
class BiteMediaController extends Controller
{
    public function index(Workspace $workspace, Request $request, BiteSummons $summons, BiteChats $chats): Response
    {
        $user = $request->user();

        abort_unless($summons->availableTo($workspace, $user), 404);

        $validated = $request->validate([
            'kind' => ['nullable', Rule::enum(MediaKind::class)],
        ]);

        $kind = isset($validated['kind']) ? MediaKind::from($validated['kind']) : null;

        return Inertia::render('bite/media', [
            'kind' => $kind?->value,
            'generations' => $chats->media($workspace, $user, $kind, $request),
        ]);
    }
}
