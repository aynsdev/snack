<?php

use App\Actions\Messages\SendMessage;
use App\Enums\ChannelType;
use App\Enums\ConnectorKind;
use App\Enums\OutboundEventType;
use App\Models\AgentSummon;
use App\Models\Message;
use App\Services\ActorContext;
use App\Services\BiteSummons;
use App\Services\MentionParser;
use App\Services\SlugGenerator;
use Illuminate\Support\Facades\Queue;

/**
 * `@agent` in a channel: who is summoned, what the dispatcher hears, and which
 * of the thread's messages leave Snack afterwards.
 */
beforeEach(function () {
    enableAgentDispatcher();
    Queue::fake();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->dev = joinWorkspace($this->workspace);
    $this->general = makeChannel($this->workspace, attributes: ['name' => 'general', 'slug' => 'general']);
    addToChannel($this->general, $this->owner);
    addToChannel($this->general, $this->dev);

    $this->actingAs($this->dev);
});

function summonSay(object $test, string $body, ?Message $replyTo = null, string $channel = 'general'): Message
{
    $id = $test->postJson(
        route('channels.messages.store', [$test->workspace->slug, $channel]),
        ['body' => $body] + ($replyTo === null ? [] : ['parent_message_id' => $replyTo->id]),
    )->assertCreated()->json('message.id');

    return Message::query()->findOrFail($id);
}

describe('summoning', function () {
    it('summons the author\'s own agent with @agent', function () {
        $message = summonSay($this, '@agent what does the login flow do?');

        $event = outboundEvents(OutboundEventType::AgentSummoned)->sole();

        expect($event->payload['actor'])->toMatchArray(['user_id' => $this->dev->id, 'via' => 'web'])
            ->and($event->payload['data'])->toMatchArray([
                'channel' => ['id' => $this->general->id, 'name' => 'general'],
                'thread_root_id' => $message->id,
                'summoner_id' => $this->dev->id,
                'target_user_id' => $this->dev->id,
            ])
            ->and($event->payload['data']['message']['id'])->toBe($message->id)
            ->and(outboundEvents(OutboundEventType::MessageCreated))->toBeEmpty()
            ->and(AgentSummon::query()->sole()->only(['channel_id', 'thread_root_id', 'target_user_id', 'summoner_id', 'message_id']))
            ->toBe([
                'channel_id' => $this->general->id,
                'thread_root_id' => $message->id,
                'target_user_id' => $this->dev->id,
                'summoner_id' => $this->dev->id,
                'message_id' => $message->id,
            ]);
    });

    it('summons the agent of the person mentioned just before @agent', function () {
        summonSay($this, "@{$this->owner->username} @agent can you review this?");

        expect(outboundEvents(OutboundEventType::AgentSummoned)->sole()->payload['data'])
            ->toMatchArray(['summoner_id' => $this->dev->id, 'target_user_id' => $this->owner->id]);
    });

    it('summons in a reply, against the thread\'s root', function () {
        $root = summonSay($this, 'The deploy is flaky');
        summonSay($this, '@agent any idea why?', $root);

        expect(outboundEvents(OutboundEventType::AgentSummoned)->sole()->payload['data']['thread_root_id'])->toBe($root->id);
    });

    it('does not summon twice for the same thread and person', function () {
        $root = summonSay($this, '@agent hello');
        summonSay($this, '@agent again', $root);

        expect(outboundEvents(OutboundEventType::AgentSummoned))->toHaveCount(1)
            ->and(outboundEvents(OutboundEventType::MessageCreated))->toHaveCount(1);
    });

    it('ignores a target who cannot read the channel', function () {
        $outsider = joinWorkspace($this->workspace);
        $private = makeChannel($this->workspace, ChannelType::Private, ['name' => 'secret', 'slug' => 'secret']);
        addToChannel($private, $this->dev);

        summonSay($this, "@{$outsider->username} @agent look", channel: 'secret');

        expect(outboundEvents(OutboundEventType::AgentSummoned))->toBeEmpty()
            ->and(AgentSummon::query()->count())->toBe(0);
    });

    it('ignores @agent inside code and as part of a longer handle', function (string $body) {
        summonSay($this, $body);

        expect(outboundEvents(OutboundEventType::AgentSummoned))->toBeEmpty();
    })->with([
        'inline code' => 'type `@agent` to summon',
        'longer handle' => '@agents are cool',
    ]);

    it('never summons from a direct message', function () {
        $dm = makeConversation($this->workspace, [$this->dev, $this->owner]);

        $this->postJson(route('conversations.messages.store', [$this->workspace->slug, $dm->public_id]), ['body' => '@agent hi'])
            ->assertCreated();

        expect(outboundEvents())->toBeEmpty();
    });

    /**
     * The phone is the person typing, the same as the browser — so `@agent`
     * from the mobile app summons, and says it came from the app.
     */
    it('summons from the mobile app, as the person via mobile', function () {
        $this->withToken(mobileToken($this->dev))
            ->postJson("/api/v1/w/{$this->workspace->slug}/channels/general/messages", ['body' => '@agent what does checkout do?'])
            ->assertCreated();

        $event = outboundEvents(OutboundEventType::AgentSummoned)->sole();

        expect($event->payload['actor'])->toMatchArray(['user_id' => $this->dev->id, 'via' => 'mobile', 'connector_id' => null])
            ->and($event->payload['data']['target_user_id'])->toBe($this->dev->id);
    });

    /**
     * An assistant writing `@agent` through the MCP server is not the person
     * asking, whichever token it holds.
     */
    it('never summons from an MCP connector or an agent', function (ConnectorKind $kind) {
        [$user] = connectAs($this->dev, $this->workspace, $kind);
        $this->app['auth']->guard('api')->setUser($user);
        $this->app['auth']->shouldUse('api');

        app(SendMessage::class)->handle(container: $this->general, sender: $user, body: '@agent hello');

        expect(app(ActorContext::class)->current()->via->isPerson())->toBeFalse()
            ->and(outboundEvents(OutboundEventType::AgentSummoned))->toBeEmpty()
            ->and(AgentSummon::query()->count())->toBe(0);
    })->with([
        'mcp token' => ConnectorKind::Token,
        'agent' => ConnectorKind::Agent,
    ]);

    it('sends nothing when no dispatcher is configured', function () {
        config(['snack.agents.dispatcher_url' => null]);

        summonSay($this, '@agent hello');

        expect(AgentSummon::query()->count())->toBe(0);
    });
});

describe('the summoned thread', function () {
    it('exports replies, edits and deletions while the summons is open', function () {
        $root = summonSay($this, '@agent what is flaky here?');

        $this->actingAs($this->owner);
        $reply = summonSay($this, 'Probably the cache', $root);

        $this->patchJson(route('messages.update', [$this->workspace->slug, $reply->id]), ['body' => 'Probably the CDN'])->assertOk();
        $this->deleteJson(route('messages.destroy', [$this->workspace->slug, $reply->id]))->assertOk();

        $created = outboundEvents(OutboundEventType::MessageCreated)->sole()->payload['data'];

        expect($created['projects'])->toBe([])
            ->and($created['message']['id'])->toBe($reply->id)
            ->and(outboundEvents(OutboundEventType::MessageUpdated)->sole()->payload['data']['message']['id'])->toBe($reply->id)
            ->and(outboundEvents(OutboundEventType::MessageDeleted)->sole()->payload['data']['message_id'])->toBe($reply->id);
    });

    it('reports the root\'s deletion and then stops exporting the thread', function () {
        $root = summonSay($this, '@agent help');
        $reply = summonSay($this, 'more detail', $root);

        $this->deleteJson(route('messages.destroy', [$this->workspace->slug, $root->id]))->assertOk();

        expect(outboundEvents(OutboundEventType::MessageDeleted)->sole()->payload['data'])
            ->toMatchArray(['message_id' => $root->id, 'thread_root_id' => $root->id])
            ->and(AgentSummon::query()->sole()->closed_at)->not->toBeNull();

        $this->deleteJson(route('messages.destroy', [$this->workspace->slug, $reply->id]))->assertOk();

        expect(outboundEvents(OutboundEventType::MessageDeleted))->toHaveCount(1);
    });

    it('leaves ordinary threads in Snack', function () {
        $root = summonSay($this, 'lunch?');
        summonSay($this, 'sure', $root);

        expect(outboundEvents())->toBeEmpty();
    });
});

describe('the reserved handle', function () {
    it('never gives a new account the username agent', function () {
        expect(app(SlugGenerator::class)->forUsername('Agent'))->not->toBe('agent');
    });

    it('does not treat @agent as a person to notify', function () {
        expect(MentionParser::extract('@agent and @ada')['usernames'])->toBe(['ada']);
    });

    it('does not let @agent summon Bite', function () {
        config(['snack.bite.enabled' => true, 'snack.bite.handle' => 'agent']);
        $message = makeMessage($this->general, $this->dev, '@agent hello');

        expect(app(BiteSummons::class)->summonedBy($message))->toBeNull();
    });
});
