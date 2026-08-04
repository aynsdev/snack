<?php

namespace App\Services\LiveKit;

use App\Models\Huddle;
use App\Models\User;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Mints the access tokens that let a browser into a LiveKit room.
 *
 * LiveKit does not talk to Laravel. A client dials the media server directly
 * and presents a JWT signed with the shared API secret; the server verifies the
 * signature and reads the permissions out of the token itself. That makes this
 * class the *entire* authorization boundary for huddle media — whatever a token
 * says, LiveKit will honour, and nothing downstream gets a second opinion. Every
 * caller must therefore have already run {@see App\Policies\HuddlePolicy}.
 *
 * # Why the JWT is written by hand
 *
 * LiveKit publishes a PHP server SDK, and the token is the only part of it this
 * application would use: everything else — rooms, participants, lifecycle — is
 * modelled in Snack's own tables. What remains is a HS256 JWT with six claims
 * and a nested grant object, which is forty lines of `hash_hmac` and
 * `base64_encode`. That is not a dependency's worth of work, and a dependency
 * here would have to be trusted with the secret.
 *
 * The claim names below are LiveKit's wire format, not this application's
 * choice: they are camelCase because the Go server unmarshals them that way, and
 * renaming any of them produces a token that verifies correctly and grants
 * nothing.
 *
 * # What the token deliberately does not grant
 *
 * `roomAdmin`, `roomList`, `roomRecord` and `roomCreate` are all absent. A
 * participant needs none of them: `roomJoin` on a named room implicitly creates
 * that room if it does not exist yet, and the rest would let anybody in a huddle
 * enumerate or evict everyone else's. The grant is scoped to exactly one room,
 * named by an unguessable ULID, for exactly one identity.
 */
class TokenIssuer
{
    /**
     * How far in the past `nbf` is backdated, in seconds.
     *
     * The media server is a separate process — routinely a separate host, and
     * in the self-hosted compose file a container with its own clock. A token
     * minted "now" and validated against a clock a few seconds behind is not yet
     * valid, and LiveKit rejects it with an error that says nothing about
     * clocks. Backdating costs nothing and removes the whole class of problem.
     */
    private const CLOCK_SKEW_LEEWAY = 60;

    private readonly ?string $apiKey;

    private readonly ?string $apiSecret;

    private readonly string $url;

    private readonly int $ttlMinutes;

    public function __construct()
    {
        $this->apiKey = config('services.livekit.key');
        $this->apiSecret = config('services.livekit.secret');
        $this->url = (string) config('services.livekit.url');
        $this->ttlMinutes = (int) config('snack.huddles.token_ttl_minutes');
    }

    /**
     * Whether this deployment can mint tokens at all.
     *
     * Read by the huddle policy so a deployment with no media server refuses at
     * the door, rather than offering a Join button that dials nothing.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== null
            && $this->apiKey !== ''
            && $this->apiSecret !== null
            && $this->apiSecret !== '';
    }

    /**
     * The signalling URL the browser should dial.
     *
     * Handed out alongside each token rather than compiled into the JavaScript
     * bundle, so the server stays the only place that knows where the media
     * lives and a redeployment does not need a rebuilt bundle to move it.
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * A join token for one person, for one huddle.
     *
     * Authorization is the caller's responsibility. Both arguments are resolved
     * models rather than client-supplied identifiers, so a request cannot ask
     * for a token naming a room it was not authorized against.
     */
    public function issue(Huddle $huddle, User $user): string
    {
        if (! $this->isConfigured()) {
            // A programming error rather than a runtime one: the policy refuses
            // long before here, so reaching this means a caller skipped it.
            throw new LogicException('LiveKit credentials are not configured.');
        }

        $now = Carbon::now();

        return $this->sign([
            'iss' => $this->apiKey,

            /*
             * The participant identity, and the only thing LiveKit uses to tell
             * two people apart. It must be stable, because the roster the media
             * server reports is matched back to Snack users by parsing it, and
             * it must be unique, because LiveKit treats a second connection
             * under an existing identity as the same participant moving — it
             * disconnects the older one. That is the behaviour we want: joining
             * a huddle from a phone should take the call off the laptop rather
             * than put the same person in the room twice, echoing themselves.
             */
            'sub' => self::identityFor($user),

            'nbf' => $now->getTimestamp() - self::CLOCK_SKEW_LEEWAY,
            'exp' => $now->getTimestamp() + ($this->ttlMinutes * 60),

            // The display name LiveKit shows for a participant that has not yet
            // published anything. Snack draws its own tiles from its own roster,
            // so this only surfaces in LiveKit's tooling and logs.
            'name' => $user->name,

            /*
             * Carried through the media server untouched and delivered to every
             * other participant. Deliberately only what a huddle tile draws:
             * everyone in the room can read this, and it reaches them without
             * passing through any Snack authorization on the way out.
             */
            'metadata' => json_encode([
                'user_id' => $user->id,
                'username' => $user->username,
                'avatar_url' => $user->avatarUrl(),
            ], JSON_THROW_ON_ERROR),

            'video' => [
                // Scoped to this one room. Named by a ULID precisely because
                // this grant is all that stands between a token holder and a
                // room — see Huddle's class docblock.
                'room' => $huddle->roomName(),
                'roomJoin' => true,

                // Speaking, listening, and screen sharing. A huddle where you
                // can only listen is a broadcast, which is a different feature.
                'canPublish' => true,
                'canSubscribe' => true,

                // The data channel, which carries LiveKit's own participant
                // metadata updates — including the mute state each tile renders.
                'canPublishData' => true,

                // Lets a participant change their own metadata mid-call and
                // nobody else's. Without it, a client that reconnects cannot
                // re-announce itself.
                'canUpdateOwnMetadata' => true,
            ],
        ]);
    }

    /**
     * The LiveKit participant identity for a Snack user.
     *
     * Prefixed rather than bare so it can never be confused with an identity
     * minted by something else pointed at the same media server, and so the
     * roster the client receives can be parsed back to user ids unambiguously.
     */
    public static function identityFor(User $user): string
    {
        return 'user.'.$user->id;
    }

    /**
     * The user id encoded in a LiveKit identity, or null if it was not minted
     * by {@see identityFor()}.
     *
     * The identity arrives from the media server, which means it arrives from
     * outside this application — so it is parsed defensively and the resulting
     * id is treated as a claim to be checked, never as an authorization.
     */
    public static function userIdFromIdentity(string $identity): ?int
    {
        if (! str_starts_with($identity, 'user.')) {
            return null;
        }

        $id = substr($identity, strlen('user.'));

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * Encode and sign the claims as a compact HS256 JWT.
     *
     * @param  array<string, mixed>  $claims
     */
    private function sign(array $claims): string
    {
        $segments = [
            self::base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            self::base64Url(json_encode($claims, JSON_THROW_ON_ERROR)),
        ];

        $payload = implode('.', $segments);

        $signature = hash_hmac('sha256', $payload, (string) $this->apiSecret, binary: true);

        return $payload.'.'.self::base64Url($signature);
    }

    /**
     * base64url per RFC 4648 §5: the URL-safe alphabet, unpadded. Ordinary
     * base64 produces `+`, `/` and `=`, none of which survive a JWT intact.
     */
    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
