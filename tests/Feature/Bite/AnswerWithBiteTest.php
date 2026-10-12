<?php

use App\Actions\Messages\SendMessage;
use App\Ai\Agents\Bite;
use App\Ai\BiteAttachments;
use App\Ai\BiteUploads;
use App\Enums\BiteCapability;
use App\Enums\WorkspaceRole;
use App\Events\BiteReplying;
use App\Jobs\AnswerWithBite;
use App\Models\BiteSetting;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\BiteBot;
use App\Services\BiteSummons;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

/**
 * The whole path, from somebody typing a question to Bite's reply landing in
 * the channel — with the model faked, because what is being protected is the
 * plumbing around it and not the provider.
 */
beforeEach(function () {
    config()->set('snack.bite.enabled', true);
    config()->set('ai.providers.anthropic.key', 'test-key');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);

    $this->ada = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    addToChannel($this->channel, $this->ada);

    $this->bot = app(BiteBot::class)->accountFor($this->workspace);
});

/**
 * Ask Bite something, the way a person does.
 *
 * The job is not run by hand: the suite's queue is `sync`, so dispatching it
 * from the message pipeline runs it inline. That is the point — what is being
 * tested is that sending a message is enough, and running it a second time here
 * would both double every reply and quietly consume a second faked response.
 */
function askBite(string $body, ?Message $parent = null): Message
{
    return app(SendMessage::class)->handle(
        container: test()->channel,
        sender: test()->ada,
        body: $body,
        parent: $parent,
    );
}

/** Bite's most recent message in the channel. */
function biteSaid(): ?Message
{
    return Message::query()
        ->where('channel_id', test()->channel->id)
        ->where('sender_id', test()->bot->id)
        ->latest('id')
        ->first();
}

it('posts its answer into the channel it was asked in', function () {
    Bite::fake(['We decided on the annual plan.']);

    askBite('@bite what did we decide about pricing?');

    expect(biteSaid()?->body)->toBe('We decided on the annual plan.');
});

it('is asked the question that was actually typed', function () {
    Bite::fake(['Sure.']);

    askBite('@bite find me the deck');

    Bite::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains(
        (string) $prompt->prompt,
        'find me the deck',
    ));
});

it('is answered by Claude Haiku', function () {
    Bite::fake(['Sure.']);

    askBite('@bite hello');

    Bite::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === 'anthropic'
        && $prompt->model === 'claude-haiku-5-5');
});

/**
 * A channel where every answer is hidden behind "1 reply" is a channel where
 * nobody reads the answers — so a top-level mention gets a top-level answer.
 */
it('answers a top-level mention at top level', function () {
    Bite::fake(['Here you go.']);

    askBite('@bite hello');

    expect(biteSaid()?->parent_message_id)->toBeNull();
});

/**
 * And an exchange inside a thread stays inside it.
 */
it('answers inside the thread it was asked in', function () {
    Bite::fake(['Here you go.']);

    $root = makeMessage($this->channel, $this->ada, 'about the migration');

    askBite('@bite what was the conclusion?', $root);

    expect(biteSaid()?->parent_message_id)->toBe($root->id);
});

/**
 * A provider that times out or refuses must leave something on screen. The
 * alternative is a mention that is silently never answered, which reads as the
 * feature being broken rather than the request being.
 */
it('says something when the model fails', function () {
    Bite::fake(function (): never {
        throw new RuntimeException('provider exploded');
    });

    askBite('@bite hello');

    expect(biteSaid()?->body)->toContain('could not answer that just now');
});

it('stays silent when the model returns nothing at all', function () {
    Bite::fake(['   ']);

    askBite('@bite hello');

    expect(biteSaid())->toBeNull();
});

/**
 * The pause. A tool that wants to write something stops, and the card is what
 * the person decides from — so it has to reach the message row, which is what
 * the timeline renders from.
 */
it('writes an approval card onto the message when a tool is waiting', function () {
    Bite::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval(
                id: 'call_abc',
                tool: 'CreateTask',
                arguments: ['project' => 'ENG', 'title' => 'Fix the redirect'],
                reason: 'Create “Fix the redirect” on ENG.',
            ),
        ])
            /*
             * A real paused response always belongs to a stored conversation —
             * that is what makes it resumable. The fake does not set one, so it
             * is supplied here; without it the card is deliberately not written
             * at all, since a button that cannot resume anything is worse than
             * no button.
             */
            ->withinConversation('conversation-under-test'),
    ]);

    askBite('@bite file a bug about the redirect');

    $reply = biteSaid();

    expect($reply)->not->toBeNull()
        ->and($reply->body)->not->toBe('')
        ->and($reply->metadata['bite']['state'])->toBe('pending')
        ->and($reply->metadata['bite']['approvals'])->toHaveCount(1)
        ->and($reply->metadata['bite']['approvals'][0]['id'])->toBe('call_abc')
        ->and($reply->metadata['bite']['approvals'][0]['summary'])->toBe('Create “Fix the redirect” on ENG.');
});

it('carries no card on an ordinary answer', function () {
    Bite::fake(['Nothing to approve here.']);

    askBite('@bite hello');

    expect(biteSaid()?->metadata['bite'] ?? null)->toBeNull();
});

/**
 * Bite's own message goes back through the pipeline, which asks again whether
 * Bite was summoned. If bots were not excluded, this is the point at which the
 * queue would start eating itself.
 */
it('does not answer its own answer', function () {
    Bite::fake(['@ada here is what I found.']);

    askBite('@bite hello');

    $replies = Message::query()
        ->where('channel_id', $this->channel->id)
        ->where('sender_id', $this->bot->id)
        ->count();

    expect($replies)->toBe(1);
});

/**
 * A message deleted between the dispatch and the run is a no-op, not a failed
 * job — which is why the job takes an id rather than a serialized model.
 */
it('does nothing when the message has been deleted before the job runs', function () {
    Bite::fake(['Should never be posted.']);

    app(AnswerWithBite::class, ['messageId' => 99_999])->handle(
        app(BiteSummons::class),
        app(BiteBot::class),
        app(BiteAttachments::class),
    );

    expect(biteSaid())->toBeNull();
    Bite::assertNeverPrompted();
});

/**
 * The reply is an ordinary message, which is the point of posting through the
 * same pipeline: it broadcasts, it indexes, it paginates, and a member reading
 * it cannot tell it took a different path.
 */
it('posts an ordinary, searchable message', function () {
    Bite::fake(['The annual plan.']);

    askBite('@bite pricing?');

    $reply = biteSaid();

    expect($reply->workspace_id)->toBe($this->workspace->id)
        ->and($reply->channel_id)->toBe($this->channel->id)
        ->and($reply->type->value)->toBe('text');
});

/**
 * # The "Bite is replying…" indicator
 *
 * An answer takes as long as it takes, and until the message lands there is
 * nothing on screen at all. That silence is indistinguishable from a summon
 * that did not register, an assistant that is switched off, and a queue worker
 * that is not running — so the wait is announced at both ends.
 */
it('says it is replying before it asks the model, and stops when it is done', function () {
    Bite::fake(['The annual plan.']);

    $summons = askBite('@bite pricing?');

    Event::assertDispatched(
        BiteReplying::class,
        fn (BiteReplying $event): bool => $event->replying === true
            && $event->summons->is($summons)
            && $event->bot->is($this->bot),
    );

    Event::assertDispatched(
        BiteReplying::class,
        fn (BiteReplying $event): bool => $event->replying === false,
    );
});

/**
 * The half that matters. An indicator that is only taken down on success is an
 * assistant that is permanently about to answer every time a provider refuses.
 */
it('stops saying it is replying when the model fails', function () {
    Bite::fake(function (): never {
        throw new RuntimeException('provider exploded');
    });

    askBite('@bite hello');

    Event::assertDispatched(
        BiteReplying::class,
        fn (BiteReplying $event): bool => $event->replying === false,
    );
});

/**
 * And when there is no answer to post at all — a model that returns nothing
 * leaves no message behind, so the indicator is the only thing that could be
 * left running.
 */
it('stops saying it is replying when the answer is empty', function () {
    Bite::fake(['   ']);

    askBite('@bite hello');

    expect(biteSaid())->toBeNull();

    Event::assertDispatched(
        BiteReplying::class,
        fn (BiteReplying $event): bool => $event->replying === false,
    );
});

/**
 * Nothing is announced for a message that was not addressed to Bite, which is
 * almost every message the application sends.
 */
it('says nothing about replying when it was not summoned', function () {
    app(SendMessage::class)->handle(
        container: $this->channel,
        sender: $this->ada,
        body: 'just talking among ourselves',
    );

    Event::assertNotDispatched(BiteReplying::class);
});

/**
 * The indicator is heard where the answer will land: a summon inside a thread
 * is answered inside that thread, so the wait belongs on the thread's channel
 * and not in front of everyone reading the container.
 */
it('announces into the thread when it was summoned in one', function () {
    $root = makeMessage($this->channel, $this->ada, 'about the migration');

    $summons = Message::factory()
        ->inChannel($this->channel)
        ->from($this->ada)
        ->replyTo($root)
        ->withBody('@bite what was the conclusion?')
        ->create();

    $channels = (new BiteReplying($summons, $this->bot, true))->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-thread.'.$root->id);
});

it('announces into the channel when it was summoned at top level', function () {
    $summons = makeMessage($this->channel, $this->ada, '@bite hello');

    $channels = (new BiteReplying($summons, $this->bot, true))->broadcastOn();

    expect($channels[0])->toBeInstanceOf(PresenceChannel::class)
        ->and($channels[0]->name)->toBe('presence-channel.'.$this->channel->id);
});

/**
 * The payload the client counts down from. Zero on the way out, because a
 * "finished" that carried a lifetime would be a contradiction.
 */
it('carries a lifetime while working and none when finished', function () {
    config()->set('snack.bite.replying_ttl_seconds', 45);

    $summons = makeMessage($this->channel, $this->ada, '@bite hello');

    expect((new BiteReplying($summons, $this->bot, true))->broadcastWith())
        ->toMatchArray(['name' => 'Bite', 'replying' => true, 'expires_in' => 45])
        ->and((new BiteReplying($summons, $this->bot, false))->broadcastWith())
        ->toMatchArray(['replying' => false, 'expires_in' => 0]);
});

/**
 * # Files handed to Bite with the question
 *
 * `read_file` is Bite going to fetch something it heard about. This is the
 * other direction: a screenshot dropped into the composer, where the file is
 * the whole question and there is no name to search for. See
 * {@see BiteUploads}.
 */
it('puts an attached file into the prompt', function () {
    Storage::fake('local');
    Storage::disk('local')->put('attachments/notes.md', 'The staging host is stage-2.');

    Bite::fake(['Reading it now.']);

    $message = askBite('@bite what does this say?');

    MessageAttachment::factory()->create([
        'workspace_id' => $this->workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => $this->ada->id,
        'disk' => 'local',
        'path' => 'attachments/notes.md',
        'original_name' => 'notes.md',
        'mime_type' => 'text/markdown',
    ]);

    // Re-run the job now the file is attached, the way the real pipeline sees
    // it: the upload is claimed inside the send transaction.
    app(AnswerWithBite::class, ['messageId' => $message->id])->handle(
        app(BiteSummons::class),
        app(BiteBot::class),
        app(BiteAttachments::class),
    );

    Bite::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains(
        (string) $prompt->prompt,
        'The staging host is stage-2.',
    ));
});

/**
 * And the admin switch closes this door as well as `read_file`'s.
 */
it('ignores an attached file when the workspace switched file reading off', function () {
    Storage::fake('local');
    Storage::disk('local')->put('attachments/secret.md', 'Do not read me.');

    BiteSetting::factory()
        ->forWorkspace($this->workspace)
        ->allowing([BiteCapability::SearchMessages])
        ->create();

    Bite::fake(['Nothing to see.']);

    $message = askBite('@bite what does this say?');

    MessageAttachment::factory()->create([
        'workspace_id' => $this->workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => $this->ada->id,
        'disk' => 'local',
        'path' => 'attachments/secret.md',
        'original_name' => 'secret.md',
        'mime_type' => 'text/markdown',
    ]);

    app(AnswerWithBite::class, ['messageId' => $message->id])->handle(
        app(BiteSummons::class),
        app(BiteBot::class),
        app(BiteAttachments::class),
    );

    Bite::assertPrompted(fn (AgentPrompt $prompt): bool => ! str_contains(
        (string) $prompt->prompt,
        'Do not read me.',
    ));
});
