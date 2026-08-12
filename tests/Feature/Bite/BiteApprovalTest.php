<?php

use App\Ai\Agents\Bite;
use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\BiteBot;
use App\Support\BiteCard;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Models\Conversation as AgentConversation;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * The button on the card.
 *
 * Two things are being protected. That pressing it actually resumes the paused
 * tool call — otherwise the pause is a dead end and the feature cannot write
 * anything at all. And that pressing somebody *else's* is refused, because the
 * SDK's `continue()` does not check ownership and says so.
 */
beforeEach(function () {
    config()->set('snack.bite.enabled', true);
    config()->set('ai.providers.mistral.key', 'test-key');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);

    $this->ada = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->bo = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    addToChannel($this->channel, $this->ada);
    addToChannel($this->channel, $this->bo);

    $this->bot = app(BiteBot::class)->accountFor($this->workspace);
    app(BiteBot::class)->joinChannel($this->channel, $this->bot);
});

/**
 * A message from Bite carrying a pending card, owned by the given person.
 */
function pendingCard(User $owner): Message
{
    $conversation = AgentConversation::query()->create([
        'id' => (string) Str::ulid(),
        'title' => 'Approval under test',
        'participant_type' => $owner->getMorphClass(),
        'participant_id' => $owner->id,
    ]);

    $message = makeMessage(test()->channel, test()->bot, 'I need your go-ahead before I create that.');

    $message->metadata = BiteCard::forApprovals($conversation->id, [
        new PendingApproval(
            id: 'call_abc',
            tool: 'CreateTask',
            arguments: ['project' => 'APO', 'title' => 'Fix the redirect'],
            reason: 'Create “Fix the redirect” on APO.',
        ),
    ]);

    $message->saveQuietly();

    return $message;
}

it('approves, resumes the agent, and posts what it did', function () {
    Bite::fake(['Filed APO-1 — Fix the redirect.']);

    $card = pendingCard($this->ada);

    $this->actingAs($this->ada)
        ->postJson(route('bite.approvals.store', [$this->workspace, $card]), [
            'decision' => 'approve',
        ])
        ->assertOk()
        ->assertJsonPath('bite.state', 'approved');

    expect($card->fresh()->metadata['bite']['state'])->toBe('approved')
        ->and($card->fresh()->metadata['bite']['approvals'])->toBe([]);

    Bite::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->hasApprovalDecisions());

    $followUp = Message::query()
        ->where('channel_id', $this->channel->id)
        ->where('sender_id', $this->bot->id)
        ->latest('id')
        ->first();

    expect($followUp->body)->toBe('Filed APO-1 — Fix the redirect.');
});

it('rejects without running the tool', function () {
    Bite::fake(['Alright, I have left it.']);

    $card = pendingCard($this->ada);

    $this->actingAs($this->ada)
        ->postJson(route('bite.approvals.store', [$this->workspace, $card]), [
            'decision' => 'reject',
        ])
        ->assertOk()
        ->assertJsonPath('bite.state', 'rejected');

    expect(Task::query()->count())->toBe(0);

    Bite::assertPrompted(function (AgentPrompt $prompt): bool {
        $decisions = $prompt->approvalDecisions;

        return $decisions instanceof Decisions
            && $decisions->get('call_abc')?->isRejected() === true;
    });
});

/**
 * The check the SDK deliberately does not make. Without it, anybody who can see
 * the card — everyone in the channel — could approve an action on somebody
 * else's behalf by posting its message id.
 */
it('refuses to let someone else approve', function () {
    Bite::fake(['Should never run.']);

    $card = pendingCard($this->ada);

    $this->actingAs($this->bo)
        ->postJson(route('bite.approvals.store', [$this->workspace, $card]), [
            'decision' => 'approve',
        ])
        ->assertNotFound();

    expect($card->fresh()->metadata['bite']['state'])->toBe('pending');

    Bite::assertNeverPrompted();
});

/**
 * The message policy, which is a separate refusal from the ownership one above
 * — a card in a channel somebody was removed from has to disappear like the
 * rest of it. Both answer 404 rather than 403: whether a private channel exists
 * is not a fact to confirm to somebody who cannot read it.
 */
it('refuses someone who cannot see the message at all', function () {
    Bite::fake(['Should never run.']);

    $stranger = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $private = makeChannel($this->workspace, ChannelType::Private);
    addToChannel($private, $this->ada);
    app(BiteBot::class)->joinChannel($private, $this->bot);

    $this->channel = $private;
    $card = pendingCard($this->ada);

    $this->actingAs($stranger)
        ->postJson(route('bite.approvals.store', [$this->workspace, $card]), [
            'decision' => 'approve',
        ])
        ->assertNotFound();

    expect($card->fresh()->metadata['bite']['state'])->toBe('pending');

    Bite::assertNeverPrompted();
});

/**
 * The second click. The card is marked resolved before the model is asked
 * precisely so an impatient double-press cannot submit the same decision
 * against a tool call the SDK has already consumed.
 */
it('refuses a decision on a card that has already been decided', function () {
    Bite::fake(['Done.', 'Done again.']);

    $card = pendingCard($this->ada);

    $this->actingAs($this->ada)
        ->postJson(route('bite.approvals.store', [$this->workspace, $card]), ['decision' => 'approve'])
        ->assertOk();

    $this->actingAs($this->ada)
        ->postJson(route('bite.approvals.store', [$this->workspace, $card]), ['decision' => 'approve'])
        ->assertNotFound();
});

it('refuses a decision it does not understand', function () {
    $card = pendingCard($this->ada);

    $this->actingAs($this->ada)
        ->postJson(route('bite.approvals.store', [$this->workspace, $card]), [
            'decision' => 'maybe',
        ])
        ->assertStatus(422);
});

it('refuses a message that carries no card', function () {
    $plain = makeMessage($this->channel, $this->bot, 'just an answer');

    $this->actingAs($this->ada)
        ->postJson(route('bite.approvals.store', [$this->workspace, $plain]), [
            'decision' => 'approve',
        ])
        ->assertNotFound();
});

/**
 * A failure while resuming must not leave the card pending, because the paused
 * call may already have been consumed — and it must not leave the person with
 * no idea what happened either.
 */
it('reports a failure while resuming without reopening the card', function () {
    Bite::fake(function (): never {
        throw new RuntimeException('provider exploded');
    });

    $card = pendingCard($this->ada);

    $this->actingAs($this->ada)
        ->postJson(route('bite.approvals.store', [$this->workspace, $card]), ['decision' => 'approve'])
        ->assertOk()
        ->assertJsonPath('bite.state', 'approved');

    $followUp = Message::query()
        ->where('channel_id', $this->channel->id)
        ->where('sender_id', $this->bot->id)
        ->latest('id')
        ->first();

    expect($followUp->body)->toContain('could not finish that');
});
