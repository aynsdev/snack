<?php

use App\Actions\Messages\SendMessage;
use App\Enums\AuditAction;
use App\Enums\ConnectorKind;
use App\Enums\OutboundEventType;
use App\Jobs\AnswerWithBite;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\OAuthToken;
use App\Services\ActorContext;
use App\Services\BiteSummons;
use App\Support\Actor;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Agent connectors: the same token, the same access, labelled differently —
 * and the label decides how everything the agent does is attributed.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->dev = joinWorkspace($this->workspace);

    $this->channel = makeChannel($this->workspace, attributes: ['name' => 'pca-agent', 'slug' => 'pca-agent']);
    addToChannel($this->channel, $this->dev);
});

/**
 * Create a connector through the page, and hand back its plaintext token —
 * which is what a person pastes into LocalAgent.
 */
function issueConnector(object $test, string $name, ?string $kind): string
{
    $payload = ['name' => $name] + ($kind === null ? [] : ['kind' => $kind]);

    $response = $test->actingAs($test->dev)
        ->post("/w/{$test->workspace->slug}/connectors", $payload)
        ->assertOk();

    auth()->forgetGuards();

    return $response->viewData('page')['flash']['connector']['token'];
}

/**
 * Post through the real MCP endpoint, as the token's owner.
 */
function sendOverMcp(object $test, string $token, string $body, ?int $replyTo = null): Message
{
    $text = $test->withToken($token)
        ->postJson('/mcp', mcpCall('tools/call', [
            'name' => 'send_message',
            'arguments' => ['channel' => 'pca-agent', 'body' => $body] + ($replyTo === null ? [] : ['reply_to' => $replyTo]),
        ]))
        ->assertOk()
        ->json('result.content.0.text');

    expect($text)->toMatch('/message #(\d+)/');

    preg_match_all('/message #(\d+)/', $text, $matches);

    return Message::query()->findOrFail((int) end($matches[1]));
}

describe('the connectors page', function () {
    it('creates an agent connector and audits its kind', function () {
        $this->actingAs($this->dev)
            ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'LocalAgent on my Mac', 'kind' => 'agent'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->hasFlash('connector.kind', 'agent')
                ->has('tokens', 1)
                ->where('tokens.0.kind', 'agent'));

        $token = OAuthToken::query()->sole();

        expect($token->connector_kind)->toBe(ConnectorKind::Agent)
            ->and(AuditLog::query()->where('action', AuditAction::ConnectorCreated)->sole()->new_values)
            ->toEqual(['name' => 'LocalAgent on my Mac', 'kind' => 'agent']);

        $this->delete("/w/{$this->workspace->slug}/connectors/tokens/{$token->id}")->assertRedirect();

        expect(AuditLog::query()->where('action', AuditAction::ConnectorRevoked)->sole()->old_values)
            ->toEqual(['name' => 'LocalAgent on my Mac', 'kind' => 'agent']);
    });

    it('still creates an ordinary token when no kind is given', function () {
        $this->actingAs($this->dev)
            ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Cursor'])
            ->assertOk();

        expect(OAuthToken::query()->sole()->connector_kind)->toBe(ConnectorKind::Token);
    });

    it('refuses a kind it does not know', function () {
        $this->actingAs($this->dev)
            ->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Odd', 'kind' => 'robot'])
            ->assertSessionHasErrors('kind');

        expect(OAuthToken::query()->count())->toBe(0);
    });

    it('counts agent connectors against the same per-person limit', function () {
        config(['snack.mcp.max_tokens_per_member' => 1]);

        $this->actingAs($this->dev)->post("/w/{$this->workspace->slug}/connectors", ['name' => 'One', 'kind' => 'token']);

        $this->post("/w/{$this->workspace->slug}/connectors", ['name' => 'Two', 'kind' => 'agent'])
            ->assertSessionHasErrors('name');

        expect(OAuthToken::query()->count())->toBe(1);
    });
});

describe('who the actor is', function () {
    beforeEach(function () {
        enableAgentDispatcher();
        Queue::fake();
        makeAgentProject($this->workspace, $this->owner, $this->channel);
    });

    it('stamps a message an agent sends over MCP, and reports it as the agent', function () {
        $token = issueConnector($this, 'LocalAgent', 'agent');
        $connector = OAuthToken::query()->sole();

        $root = makeMessage($this->channel, $this->dev, 'Thread root');
        $message = sendOverMcp($this, $token, 'Plan for PCA-497', $root->id);

        expect($message->sender_id)->toBe($this->dev->id)
            ->and($message->metadata['agent'])->toEqual(['connector_id' => $connector->id, 'name' => 'LocalAgent']);

        $event = outboundEvents(OutboundEventType::MessageCreated)->sole();

        expect($event->payload['actor'])->toEqual([
            'user_id' => $this->dev->id,
            'via' => 'agent',
            'connector_id' => $connector->id,
            'is_bot' => false,
        ])
            ->and($event->payload['data']['message']['agent'])->toEqual(['connector_id' => $connector->id, 'name' => 'LocalAgent']);
    });

    it('reports an ordinary connector as mcp and leaves the message unstamped', function () {
        $token = issueConnector($this, 'Cursor', null);
        $connector = OAuthToken::query()->sole();

        $root = makeMessage($this->channel, $this->dev, 'Thread root');
        $message = sendOverMcp($this, $token, 'Asked Cursor to say hi', $root->id);

        expect($message->metadata)->not->toHaveKey('agent');

        expect(outboundEvents(OutboundEventType::MessageCreated)->sole()->payload['actor'])->toEqual([
            'user_id' => $this->dev->id,
            'via' => 'mcp',
            'connector_id' => $connector->id,
            'is_bot' => false,
        ]);
    });

    it('stamps an agent reply in a thread', function () {
        $root = makeMessage($this->channel, $this->owner, 'Thread root');
        $token = issueConnector($this, 'LocalAgent', 'agent');

        $reply = sendOverMcp($this, $token, 'Revised plan', $root->id);

        expect($reply->parent_message_id)->toBe($root->id)
            ->and($reply->metadata)->toHaveKey('agent');
    });

    it('reports a change an agent makes to a task as the agent', function () {
        $project = $this->workspace->projects()->sole();
        addToProject($project, $this->dev);
        $task = makeTask($project, $this->owner);
        $token = issueConnector($this, 'LocalAgent', 'agent');

        $this->withToken($token)
            ->postJson('/mcp', mcpCall('tools/call', [
                'name' => 'update_task',
                'arguments' => ['task' => $task->reference(), 'assignee' => $this->dev->username],
            ]))
            ->assertOk();

        expect($task->fresh()->assignee_id)->toBe($this->dev->id)
            ->and(outboundEvents(OutboundEventType::TaskEligibilityChanged)->sole()->payload['actor']['via'])->toBe('agent');
    });

    it('is the system when nobody is signed in', function () {
        expect(app(ActorContext::class)->current()->toArray())->toBe([
            'user_id' => null,
            'via' => 'system',
            'connector_id' => null,
            'is_bot' => false,
        ]);
    });

    it('never stamps a message whose sender is not the agent\'s owner', function () {
        issueConnector($this, 'LocalAgent', 'agent');
        $connector = OAuthToken::query()->sole();

        $message = app(ActorContext::class)->using(
            Actor::agent($this->dev, $connector),
            fn (): Message => app(SendMessage::class)->handle($this->channel, $this->owner, 'Not the agent owner'),
        );

        expect($message->metadata)->toBe([]);
    });
});

describe('how an agent message is shown and heard', function () {
    it('shows the agent\'s name, and nothing else of the connector, to readers', function () {
        $message = makeMessage($this->channel, $this->dev, 'Plan');
        $message->forceFill(['metadata' => ['agent' => ['connector_id' => 'secret-token-id', 'name' => 'LocalAgent']]])->save();

        $response = $this->actingAs($this->dev)
            ->getJson(route('channels.messages.index', [$this->workspace->slug, $this->channel->slug]))
            ->assertOk();

        $payload = collect($response->json('messages'))->firstWhere('id', $message->id);

        expect($payload['agent'])->toBe(['name' => 'LocalAgent', 'kind' => null, 'task_id' => null, 'task_assignee_id' => null])
            ->and(json_encode($response->json()))->not->toContain('secret-token-id');
    });

    it('is never summoned into answering an agent', function () {
        config()->set('snack.bite.enabled', true);
        config()->set('ai.default', 'mistral');
        config()->set('ai.providers.mistral.key', 'test-key');
        Queue::fake();

        $person = app(SendMessage::class)->handle($this->channel, $this->dev, '@bite what is PCA-497 about?');

        $token = issueConnector($this, 'LocalAgent', 'agent');
        $agent = sendOverMcp($this, $token, '@bite what is PCA-497 about?');

        expect(app(BiteSummons::class)->summonedBy($person))->not->toBeNull()
            ->and(app(BiteSummons::class)->summonedBy($agent))->toBeNull();

        Queue::assertPushed(AnswerWithBite::class, 1);
        Queue::assertPushed(AnswerWithBite::class, fn (AnswerWithBite $job): bool => (fn () => $this->messageId)->call($job) === $person->id);
    });
});
