<?php

use App\Actions\Messages\SendMessage;
use App\Ai\Agents\Bite;
use App\Ai\BiteAttachments;
use App\Ai\BiteContext;
use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\User;
use App\Services\BiteBot;
use App\Support\BiteCard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
    config()->set('ai.providers.anthropic.key', 'test-key');

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

/**
 * # The regression that made every real approval fail
 *
 * `Bite::messages()` overrides the `RemembersConversations` trait method that
 * loads the stored agent conversation. Before the alias, that override simply
 * replaced it — so a resume sent the *channel's* transcript and not the stored
 * turns, the SDK walked back to Bite's own "I need your go-ahead" message,
 * found an assistant message with no tool calls, and rejected the decision as
 * naming a tool call that does not exist. Every approval answered "I could not
 * finish that — something went wrong on my side."
 *
 * The suite did not catch it because the fake gateway never runs the approval
 * validation the real provider path does, so these assert on the messages the
 * agent would actually send.
 */
it('sends the stored conversation, not the room, when resuming', function () {
    $conversation = AgentConversation::query()->create([
        'id' => (string) Str::ulid(),
        'title' => 'Resume under test',
        'participant_type' => $this->ada->getMorphClass(),
        'participant_id' => $this->ada->id,
    ]);

    // What the room said, including Bite's own card message — the assistant
    // message that used to mask the paused tool call.
    makeMessage($this->channel, $this->ada, 'please file that bug');
    makeMessage($this->channel, $this->bot, 'I need your go-ahead before I create that.');

    $agent = (new Bite(
        new BiteContext(
            workspace: $this->workspace,
            user: $this->ada,
            container: $this->channel,
        ),
        $this->bot,
        app(BiteAttachments::class),
    ))->continue($conversation->id, as: $this->ada);

    $bodies = collect($agent->messages())
        ->map(fn ($message): string => (string) $message->content)
        ->implode(' | ');

    expect($bodies)->not->toContain('please file that bug')
        ->and($bodies)->not->toContain('I need your go-ahead');
});

it('sends the room transcript when it is not resuming', function () {
    makeMessage($this->channel, $this->ada, 'please file that bug');

    $agent = new Bite(
        new BiteContext(
            workspace: $this->workspace,
            user: $this->ada,
            container: $this->channel,
        ),
        $this->bot,
        app(BiteAttachments::class),
    );

    $bodies = collect($agent->messages())
        ->map(fn ($message): string => (string) $message->content)
        ->implode(' | ');

    expect($bodies)->toContain('please file that bug');
});

/**
 * The real provider path rather than the fake gateway, which skips the
 * replay validation that broke this. Bite searches before it files, so the
 * paused turn holds two tool calls while the stored raw blocks hold only the
 * last one — and Anthropic refuses a tool result with no tool use before it.
 */
it('resumes an approval that followed another tool call in the same turn', function () {
    config()->set('ai.conversations.generate_title', false);

    makeProject($this->workspace, $this->ada, ['name' => 'Web', 'key' => 'PWA']);

    $step = fn (string $stopReason, array $content): array => [
        'id' => 'msg_'.Str::random(8),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-haiku-4-5',
        'stop_reason' => $stopReason,
        'content' => $content,
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push($step('tool_use', [['type' => 'tool_use', 'id' => 'toolu_search', 'name' => 'SearchTasks', 'input' => ['query' => 'emergency contact']]]))
        ->push($step('tool_use', [['type' => 'tool_use', 'id' => 'toolu_create', 'name' => 'CreateTask', 'input' => ['project' => 'PWA', 'title' => 'Fix emergency contact creation']]]))
        ->push($step('end_turn', [['type' => 'text', 'text' => 'Filed PWA-1.']])),
    ]);

    $root = makeMessage($this->channel, $this->bo, 'Creating an emergency contact fails with a 500.');

    app(SendMessage::class)->handle(
        container: $this->channel,
        sender: $this->ada,
        body: '@bite create a ticket for this',
        parent: $root,
    );

    $card = Message::query()->where('sender_id', $this->bot->id)->latest('id')->firstOrFail();

    $this->actingAs($this->ada)
        ->postJson(route('bite.approvals.store', [$this->workspace, $card]), ['decision' => 'approve'])
        ->assertOk();

    $resume = Http::recorded()->last()[0];

    $toolUses = collect($resume->data()['messages'])
        ->where('role', 'assistant')
        ->flatMap(fn (array $message): array => $message['content'])
        ->where('type', 'tool_use')
        ->pluck('id');

    $toolResults = collect($resume->data()['messages'])
        ->where('role', 'user')
        ->flatMap(fn (array $message): array => is_array($message['content']) ? $message['content'] : [])
        ->where('type', 'tool_result')
        ->pluck('tool_use_id');

    expect($toolResults->diff($toolUses))->toBeEmpty()
        ->and(Task::query()->where('title', 'Fix emergency contact creation')->exists())->toBeTrue()
        ->and(Message::query()->where('sender_id', $this->bot->id)->latest('id')->value('body'))->toBe('Filed PWA-1.');
});

/**
 * PCA-575, end to end: the card remembers which message asked, and the resume
 * hands it back to the tool, so the screenshot on that message reaches the
 * card the person approved.
 */
it('takes the asking message\'s files onto the task it approves', function () {
    config()->set('ai.conversations.generate_title', false);
    Storage::fake('local');

    makeProject($this->workspace, $this->ada, ['name' => 'Client App', 'key' => 'PCA']);

    $step = fn (string $stopReason, array $content): array => [
        'id' => 'msg_'.Str::random(8),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-haiku-4-5',
        'stop_reason' => $stopReason,
        'content' => $content,
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ];

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push($step('tool_use', [['type' => 'tool_use', 'id' => 'toolu_create', 'name' => 'CreateTask', 'input' => ['project' => 'PCA', 'title' => 'Checkout button overlaps the total']]]))
        ->push($step('end_turn', [['type' => 'text', 'text' => 'Filed PCA-1.']])),
    ]);

    $upload = MessageAttachment::factory()->orphaned()->image()->create([
        'workspace_id' => $this->workspace->id,
        'uploaded_by' => $this->ada->id,
        'original_name' => 'IMG_2289.png',
    ]);
    Storage::disk('local')->put($upload->path, 'png bytes');
    Storage::disk('local')->put((string) $upload->thumbnail_path, 'thumb bytes');

    $asking = app(SendMessage::class)->handle(
        container: $this->channel,
        sender: $this->ada,
        body: '@bite file this',
        attachments: collect([$upload]),
    );

    $card = Message::query()->where('sender_id', $this->bot->id)->latest('id')->firstOrFail();

    expect($card->metadata['bite']['request_message_id'])->toBe($asking->id);

    $this->actingAs($this->ada)
        ->postJson(route('bite.approvals.store', [$this->workspace, $card]), ['decision' => 'approve'])
        ->assertOk();

    $task = Task::query()->where('title', 'Checkout button overlaps the total')->sole();
    $copy = MessageAttachment::query()->where('task_id', $task->id)->sole();

    expect($copy->original_name)->toBe('IMG_2289.png')
        ->and(Storage::disk('local')->get($copy->path))->toBe('png bytes')
        ->and($task->description)->toContain("Requested in #{$this->channel->name} (message #{$asking->id})");
});
