<?php

use App\Actions\Messages\SendMessage;
use App\Ai\Agents\Bite;
use App\Ai\BiteAttachments;
use App\Enums\WorkspaceRole;
use App\Jobs\AnswerWithBite;
use App\Models\Message;
use App\Services\BiteBot;
use App\Services\BiteSummons;
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
    config()->set('ai.providers.mistral.key', 'test-key');

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
