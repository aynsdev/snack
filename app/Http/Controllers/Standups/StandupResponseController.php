<?php

namespace App\Http\Controllers\Standups;

use App\Actions\Standups\SubmitStandupResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Standups\StoreStandupResponseRequest;
use App\Models\StandupResponse;
use App\Models\Workspace;
use App\Support\StandupCard;
use Illuminate\Http\JsonResponse;

/**
 * Answering a standup.
 *
 * # Why this answers with JSON
 *
 * The form is a card inside a direct message, and the conversation around it is
 * a live client-owned timeline that merges paginated history, socket arrivals,
 * and optimistic sends. An Inertia visit would replace the page's props and
 * throw all of that away — the reader would lose their scroll position and any
 * panel they had open, to submit a form that is three lines high. The client
 * swaps the card in place from the payload returned here.
 *
 * # Why the response id is the address
 *
 * A response row already names its run, its standup, and its person. A route
 * carrying the standup as well would invite a request that names one standup
 * and somebody else's answer to another — the same reason editing a message
 * addresses the message rather than the channel it is in.
 *
 * Ownership is the whole gate: {@see App\Policies\StandupPolicy::respond()}
 * refuses anybody but the person who was asked, and refuses everybody once the
 * run has been written up.
 */
class StandupResponseController extends Controller
{
    public function store(
        Workspace $workspace,
        StandupResponse $response,
        StoreStandupResponseRequest $request,
        SubmitStandupResponse $submit,
    ): JsonResponse {
        $submit->handle(
            response: $response,
            answers: $request->answers(),
            skipped: $request->boolean('skipped'),
        );

        /*
         * The card as it now stands, so the message the person is looking at
         * can be swapped for the answered version without a reload. Relations
         * are loaded rather than left lazy because the card reads through the
         * run to the standup and its channel.
         */
        $response->load(['run.standup.channel']);

        return response()->json([
            'standup' => StandupCard::for($response),
        ]);
    }
}
