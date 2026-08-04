<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Huddle;
use App\Models\User;
use App\Services\LiveKit\TokenIssuer;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Huddle authorization.
 *
 * # Everything derives from the container
 *
 * A huddle has no membership of its own to check. Who may start one, walk into
 * one, or even know one is happening is entirely a property of the channel or
 * conversation it hangs off — so every decision here delegates to that
 * container's own policy rather than restating it. A private channel's huddle is
 * invisible to a non-member for exactly the reason the channel is, and it stays
 * that way automatically if the channel rules ever change.
 *
 * The right delegated to is `post`, not `view`. Joining a huddle puts your voice
 * in the room, which is a contribution — the same standard reactions and pins
 * are held to. Someone previewing a public channel they have not joined can see
 * that a huddle is happening and cannot walk into it, which is the same answer
 * they get from the composer.
 *
 * # Why the media server's absence is an authorization question
 *
 * LiveKit is separate infrastructure and a deployment may simply not have it.
 * That is checked here rather than in a controller because it is the same kind
 * of fact as the others — whether this person, on this deployment, may join this
 * huddle — and putting it here means the client's rendered affordances and the
 * server's answer come from one place. The huddle button disappears rather than
 * dialling a server that is not there.
 */
class HuddlePolicy
{
    public function __construct(private readonly TokenIssuer $tokens) {}

    /**
     * Starting a huddle in a container, or walking into the live one already
     * there — the two are the same request, since {@see App\Actions\Huddles\JoinHuddle}
     * decides which of them actually happens.
     *
     * Invoked with the container as an argument:
     * `$user->can('create', [Huddle::class, $channel])`.
     */
    public function create(User $user, Channel|Conversation $container): Response
    {
        $available = $this->featureAvailable();

        if ($available->denied()) {
            return $available;
        }

        /*
         * Delegated wholesale. `post` settles container visibility before
         * anything else — see ChannelPolicy::post — so a private channel this
         * person was never in answers 404 here too, rather than confirming its
         * existence by refusing differently.
         */
        return Gate::forUser($user)->inspect('post', $container);
    }

    /**
     * Knowing a huddle is happening, and seeing who is in it.
     *
     * Read access to the container is enough: the huddle bar is part of the
     * channel, and someone previewing a public channel should see that a
     * conversation is under way even though they cannot join it yet.
     */
    public function view(User $user, Huddle $huddle): Response
    {
        $container = $huddle->container();

        if ($container === null) {
            return Response::denyAsNotFound();
        }

        return Gate::forUser($user)->inspect('view', $container);
    }

    /**
     * Connecting to a live huddle.
     *
     * The capacity check lives here rather than in the action because a full
     * huddle and an unauthorized one need the same treatment from the client:
     * a refusal it can render, before a token is minted and a connection
     * attempted.
     */
    public function join(User $user, Huddle $huddle): Response
    {
        if (! $huddle->isLive()) {
            return Response::deny('This huddle has ended.');
        }

        $container = $huddle->container();

        if ($container === null) {
            return Response::denyAsNotFound();
        }

        $allowed = $this->create($user, $container);

        if ($allowed->denied()) {
            return $allowed;
        }

        /*
         * Someone already in the huddle is always let back in, whatever the
         * count says. They are reconnecting after a dropped connection, and
         * refusing them because the room they are already in is full is the
         * most infuriating possible reading of a capacity limit.
         */
        if ($huddle->hasActiveParticipant($user)) {
            return Response::allow();
        }

        $limit = (int) config('snack.huddles.max_participants');

        return $huddle->activeParticipants()->count() < $limit
            ? Response::allow()
            : Response::deny('This huddle is full.');
    }

    /**
     * Hanging up.
     *
     * Only requires being able to see the huddle: leaving is idempotent, and
     * somebody whose posting rights were revoked mid-call — a channel archived
     * under them — must still be able to get out of the room.
     */
    public function leave(User $user, Huddle $huddle): Response
    {
        return $this->view($user, $huddle);
    }

    /**
     * Whether this deployment does huddles at all.
     *
     * Both halves are checked because they answer different questions: the
     * config flag is an operator's decision to switch the feature off, and the
     * issuer's readiness is whether credentials exist to mint a token with. A
     * deployment can plausibly be in either state alone.
     */
    private function featureAvailable(): Response
    {
        if (config('snack.huddles.enabled') !== true || ! $this->tokens->isConfigured()) {
            return Response::deny('Huddles are not available on this workspace.');
        }

        return Response::allow();
    }
}
