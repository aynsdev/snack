<?php

namespace App\Services\LiveKit;

use App\Http\Resources\HuddleResource;
use App\Models\Huddle;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Everything one person's browser needs to dial into one huddle.
 *
 * The join endpoints for channels and conversations are otherwise near-identical
 * thin wrappers, and this is the part of them that is not trivial: minting a
 * credential, and pairing it with the address it is valid at. Keeping it in one
 * place means the two endpoints cannot come to disagree about the shape of a
 * connection, and there is exactly one line in the application that puts a token
 * into a response.
 *
 * Mirrors the `HuddleConnection` type in resources/js/types/snack.ts.
 */
class HuddleConnection
{
    public function __construct(private readonly TokenIssuer $tokens) {}

    /**
     * @return array{huddle: array<string, mixed>, connection: array<string, string>}
     */
    public function for(Huddle $huddle, User $user, Request $request): array
    {
        // Re-read so the roster in the response includes the person who just
        // joined; the instance the action returned predates their own row.
        $huddle->loadMissing(['channel', 'conversation', 'activeParticipants.user']);

        return [
            'huddle' => (new HuddleResource($huddle))->resolve($request),

            'connection' => [
                /*
                 * Where to dial. Sent per response rather than compiled into the
                 * bundle so the media server can move without a rebuilt
                 * frontend — see TokenIssuer::url().
                 */
                'url' => $this->tokens->url(),

                /*
                 * The credential itself. Scoped to this one room and this one
                 * identity, and short-lived — see snack.huddles.token_ttl_minutes.
                 *
                 * This is the only place a token enters a response, and it is
                 * deliberately not part of HuddleResource: that shape is
                 * broadcast to everyone with the channel open, and a token in it
                 * would hand every one of them the means to join.
                 */
                'token' => $this->tokens->issue($huddle, $user),

                /*
                 * The identity the token was minted for. The client needs it to
                 * tell its own tile apart from everyone else's in the roster
                 * LiveKit reports, and deriving it a second time in TypeScript
                 * would be a second definition of a format the server owns.
                 */
                'identity' => TokenIssuer::identityFor($user),
            ],
        ];
    }
}
