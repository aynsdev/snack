<?php

namespace App\Ai;

use App\Enums\WorkspaceRole;
use App\Models\BiteSetting;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;

/**
 * Who is asking, and therefore what the answer may contain.
 *
 * # This is the security boundary
 *
 * Bite posts as a bot account that is a member of the workspace, so if its
 * tools queried as *themselves* it would be able to read every project and
 * every channel it had ever been added to, and repeat what it found to whoever
 * happened to ask. That is not a subtle leak: it is a chat bot that answers
 * "what did the founders say about layoffs" for anybody who types it.
 *
 * So no tool ever queries as the bot. Every one of them narrows to
 * {@see self::$user} — the person whose message summoned Bite — using the same
 * scopes the equivalent screen uses: {@see App\Services\MessageVisibility} for
 * anything reached through a message, `Channel::scopeVisibleTo` for the channel
 * list, `Project::scopeWhereMember` for projects, and the policies for
 * everything else. Bite's own membership grants it nothing.
 *
 * The rule this enforces is that Bite can only ever tell you something you
 * could have found by navigating. It is faster than you at finding it; it is
 * never allowed to be better informed.
 *
 * # Why the container is here
 *
 * Two reasons. It is where the reply goes, and it is what
 * {@see App\Ai\Agents\Bite} reads for conversational context — the last few
 * messages of the thread or DM, so that a follow-up question means something.
 * A channel Bite was summoned into is a container the asker can obviously see;
 * it is still filtered like anything else, because "obviously" is how leaks get
 * written.
 */
final readonly class BiteContext
{
    public function __construct(
        public Workspace $workspace,
        public User $user,
        public Channel|Conversation $container,
        /**
         * The message that summoned Bite, when there was one.
         *
         * Null when Bite is asked outside the message pipeline — a test, or a
         * future console entry point. Tools must not depend on it.
         */
        public ?Message $message = null,
    ) {}

    /**
     * How this workspace has configured its assistant.
     *
     * Resolved on demand rather than taken in the constructor, so that every
     * caller that builds a context — the job, the approval controller, a test —
     * cannot forget to pass it and silently get the defaults. The relation is
     * memoized on the workspace model after the first read, so asking repeatedly
     * costs one query for the whole answer.
     *
     * These settings narrow what Bite does. They never widen what it may know:
     * that is decided per person by the rules on this class, and an admin has no
     * control over it. See {@see App\Models\BiteSetting}.
     */
    public function settings(): BiteSetting
    {
        return BiteSetting::forWorkspace($this->workspace);
    }

    /**
     * The asker's standing in this workspace.
     *
     * Never null in practice — a non-member cannot post a message that summons
     * Bite — but the callers treat null as "sees nothing" rather than trusting
     * that, because the alternative failure is silent and total.
     */
    public function role(): ?WorkspaceRole
    {
        return $this->user->roleIn($this->workspace);
    }

    /**
     * Whether the asker is a guest.
     *
     * Guests are confined to the channels they were invited to, and are refused
     * the asset library and projects outright. The tools ask this before
     * offering either, so that Bite says "you do not have access to projects"
     * rather than listing the names of projects a contractor should not know
     * exist.
     */
    public function isGuest(): bool
    {
        return $this->role()?->isGuest() === true;
    }

    /**
     * A label for the container, for the system prompt.
     *
     * Bite is told where it is standing so that "this channel" resolves to
     * something. Conversations are named by their participants rather than an
     * id, which is what a person would call them.
     */
    public function containerLabel(): string
    {
        if ($this->container instanceof Channel) {
            return '#'.$this->container->name;
        }

        return 'a direct message';
    }
}
