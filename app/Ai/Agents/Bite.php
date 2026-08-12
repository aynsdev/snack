<?php

namespace App\Ai\Agents;

use App\Ai\BiteAttachments;
use App\Ai\BiteContext;
use App\Ai\BiteFiles;
use App\Ai\Tools;
use App\Models\Message;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The workspace assistant.
 *
 * Bite is addressed the way a colleague is — `@bite` in a channel, or a direct
 * message — and answers out of the workspace's own data: what was said, what
 * files exist, what is on the boards, who is here. It can also file a task,
 * which is the only thing it can change and the only thing it asks before doing.
 *
 * # What it is allowed to know
 *
 * Only what the person asking is allowed to know. That rule is enforced in
 * {@see BiteContext} and restated in every tool, and it is the single most
 * important property of this class: Bite runs as a bot account that belongs to
 * the workspace, so a tool that queried as *itself* would happily read out a
 * private channel to whoever typed the question.
 *
 * # Why the conversation is remembered twice
 *
 * `RemembersConversations` persists the agent's own turns to
 * `agent_conversations`, which is what makes a paused tool approval resumable —
 * the tool call has to still exist when the person presses the button minutes
 * later. But the *chat* Bite is standing in is the real conversation, and
 * people expect "what about the other one?" to work whether or not the previous
 * turn happened to involve Bite. So {@see self::messages()} feeds it the actual
 * thread as well.
 *
 * The two are not redundant: one is Bite's memory of what it did, the other is
 * the room's memory of what was said.
 */
#[Provider(Lab::Mistral)]
#[MaxSteps(8)]
class Bite implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function __construct(
        public BiteContext $context,
        public User $bot,
        public BiteAttachments $attachments,
    ) {}

    public function instructions(): string
    {
        $workspace = $this->context->workspace->name;
        $asker = $this->context->user->name;
        $handle = $this->context->user->username;
        $where = $this->context->containerLabel();
        $today = now($this->context->user->timezone ?? config('app.timezone'))->toDayDateTimeString();

        return <<<TEXT
        You are Bite, the assistant inside a team chat application called Snack.
        You are talking to {$asker} (@{$handle}) in {$where}, in the {$workspace}
        workspace. It is currently {$today} in their timezone.

        # What you are for

        Answering questions about this workspace using its own data — what people
        said, what files exist, what is on the project boards, who works here —
        and filing tasks when asked. You are not a general-purpose chatbot. If
        someone asks you something that has nothing to do with this workspace,
        answer briefly and without ceremony.

        # How to answer

        Search before you answer. You do not know anything about this workspace
        that a tool has not told you in this conversation, so never answer a
        factual question about it from memory — call a tool, and if the tools
        find nothing, say you could not find it. Never invent a channel name, a
        file name, a task reference, a date, or a quote. Being wrong here is much
        worse than being unhelpful: people will act on what you say without
        checking it.

        Cite where things came from. When you use something you found, say which
        channel and roughly when, so the person can go and read it themselves.

        Be brief. This is a chat message, not a report. A couple of sentences is
        usually right, and a short list is better than a paragraph of prose.
        Never open with a restatement of the question or with "Great question".

        Say what you cannot see. Your tools only return things this person is
        allowed to read, so an empty result may mean the thing exists somewhere
        they have no access to. "I could not find anything about that" is the
        honest phrasing; "that does not exist" is not.

        # Formatting

        Plain text with simple Markdown. `*bold*`, `_italic_`, `` `code` ``,
        ```` ``` ```` fences, and `-` bullets all render. Headings do not — do not
        use them. Mention people as @username and channels as #channel-name only
        when you mean to link them.
        TEXT;
    }

    /**
     * The room's memory: the last stretch of this thread or DM.
     *
     * Deliberately not {@see MessageHistory}. That builds the timeline the
     * *browser* renders — it serializes through MessageResource and asks
     * `request()->user()` what each message may be used for — and this runs on
     * a queue worker where there is no request and no authenticated user. What
     * is needed here is much smaller anyway: bodies and authors.
     *
     * Reading a thread rather than the container when Bite was summoned inside
     * one, because a reply's context is its thread; the container timeline
     * excludes replies entirely (see `Message::scopeRootLevel`) and would hand
     * Bite a conversation it is not part of.
     *
     * No visibility filter, and none needed: the container was resolved from a
     * message the asker themselves posted into it, so every message here is one
     * they are already looking at.
     *
     * @return iterable<int, \Laravel\Ai\Messages\Message>
     */
    public function messages(): iterable
    {
        $limit = (int) config('snack.bite.context_messages', 20);

        /*
         * `parent_message_id`, not `threadRootId()` — that falls back to the
         * message's own id, so it is never null and would send a plain channel
         * message down the thread branch, where the "thread" is one message
         * with no replies and the channel's actual context is missing.
         */
        $root = $this->context->message?->parent_message_id;

        $query = $root !== null
            ? Message::query()->where(fn ($group) => $group
                ->where('parent_message_id', $root)
                ->orWhere('id', $root))
            : $this->context->container->messages()->getQuery()->whereNull('parent_message_id');

        return $query
            ->with('sender:id,name,username')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            // Queried newest-first to walk the index, then flipped: a
            // transcript reads forwards.
            ->reverse()
            ->values()
            ->map(fn (Message $message): \Laravel\Ai\Messages\Message => new \Laravel\Ai\Messages\Message(
                /*
                 * Bite's own past messages are the assistant's turns; everything
                 * else is the user's. A model that saw its own words attributed
                 * to the person it is talking to will contradict itself trying
                 * to be agreeable.
                 */
                $message->sender_id === $this->bot->id ? 'assistant' : 'user',
                $this->transcribe($message),
            ))
            ->all();
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        $files = app(BiteFiles::class);

        return [
            new Tools\SearchMessages($this->context),
            new Tools\BrowseWorkspace($this->context),
            new Tools\FindFiles($this->context),
            new Tools\ReadFile($this->context, $files),
            new Tools\AttachFile($this->context, $files, $this->attachments, $this->bot),
            new Tools\SearchTasks($this->context),
            new Tools\CreateTask($this->context),
        ];
    }

    /**
     * One historical message, as a line of transcript.
     *
     * Named, because a group channel's history is unreadable without knowing
     * who said what — and because Bite is regularly asked to summarize exactly
     * that. Its own messages are left unlabelled: the role already says.
     */
    private function transcribe(Message $message): string
    {
        if ($message->sender_id === $this->bot->id) {
            return $message->body;
        }

        return "{$message->sender->name} (@{$message->sender->username}): {$message->body}";
    }
}
