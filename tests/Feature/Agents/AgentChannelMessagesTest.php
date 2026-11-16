<?php

use App\Actions\Messages\DeleteMessage;
use App\Actions\Messages\SendMessage;
use App\Actions\Messages\UpdateMessage;
use App\Enums\ChannelType;
use App\Enums\ConnectorKind;
use App\Enums\OutboundEventType;
use App\Models\IncomingWebhook;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\OAuthToken;
use App\Models\OutboundEvent;
use App\Services\ActorContext;
use App\Services\Outbound\OutboundEvents;
use App\Support\Actor;
use Illuminate\Support\Facades\Queue;

/**
 * Messages leave Snack only from a channel a project lead made some live
 * project's agent channel — never from anywhere else, whoever sends them.
 */
beforeEach(function () {
    enableAgentDispatcher();
    Queue::fake();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->dev = joinWorkspace($this->workspace);

    $this->agentChannel = makeChannel($this->workspace, attributes: ['name' => 'pca-agent', 'slug' => 'pca-agent']);
    $this->general = makeChannel($this->workspace, attributes: ['name' => 'general', 'slug' => 'general']);

    foreach ([$this->agentChannel, $this->general] as $channel) {
        addToChannel($channel, $this->owner);
        addToChannel($channel, $this->dev);
    }

    $this->project = makeAgentProject($this->workspace, $this->owner, $this->agentChannel, ['name' => 'Pedi Client App', 'key' => 'PCA']);

    $this->actingAs($this->dev);
});

function postIn(object $test, string $channelSlug, string $body, ?Message $replyTo = null): Message
{
    $id = $test->postJson(
        route('channels.messages.store', [$test->workspace->slug, $channelSlug]),
        ['body' => $body] + ($replyTo === null ? [] : ['parent_message_id' => $replyTo->id]),
    )
        ->assertCreated()
        ->json('message.id');

    return Message::query()->findOrFail($id);
}

/**
 * @return array<string, mixed>
 */
function messageEventData(OutboundEventType $type): array
{
    return outboundEvents($type)->sole()->payload['data'];
}

it('exports a thread reply in an agent channel', function () {
    $root = postIn($this, 'pca-agent', 'Plan for PCA-497');
    $message = postIn($this, 'pca-agent', '/approve looks **good**', $root);

    $event = outboundEvents(OutboundEventType::MessageCreated)->sole();
    $data = $event->payload['data'];

    expect($data['channel'])->toEqual(['id' => $this->agentChannel->id, 'name' => 'pca-agent'])
        ->and($data['projects'])->toHaveCount(1)
        ->and($data['projects'][0]['id'])->toBe($this->project->id)
        ->and($data['projects'][0]['archived'])->toBeFalse()
        ->and($data['projects'][0]['agent']['channel_id'])->toBe($this->agentChannel->id)
        ->and($data['message'])->toEqual([
            'id' => $message->id,
            'parent_message_id' => $root->id,
            'thread_root_id' => $root->id,
            'sender_id' => $this->dev->id,
            'sender_is_bot' => false,
            'type' => 'text',
            'body' => '/approve looks **good**',
            'body_plaintext' => $message->body_plaintext,
            'agent' => null,
            'attachments' => [],
            'created_at' => $message->created_at->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'edited_at' => null,
        ])
        ->and($event->payload['actor'])->toEqual([
            'user_id' => $this->dev->id,
            'via' => 'web',
            'connector_id' => null,
            'is_bot' => false,
        ]);
});

/**
 * The files go by the id `read_file` takes, so the receiver can open the
 * screenshot a reply is about without searching for it.
 */
it('lists a reply\'s files in the exported message', function () {
    $root = postIn($this, 'pca-agent', 'Plan for PCA-497');

    $files = MessageAttachment::factory()->orphaned()->count(2)->sequence(
        ['original_name' => 'IMG_2289.png', 'mime_type' => 'image/png', 'size' => 2048],
        ['original_name' => 'notes.pdf', 'mime_type' => 'application/pdf', 'size' => 512],
    )->create(['workspace_id' => $this->workspace->id, 'uploaded_by' => $this->dev->id]);

    $this->postJson(route('channels.messages.store', [$this->workspace->slug, 'pca-agent']), [
        'body' => 'This is what I see',
        'parent_message_id' => $root->id,
        'attachment_ids' => $files->pluck('id')->all(),
    ])->assertCreated();

    expect(messageEventData(OutboundEventType::MessageCreated)['message']['attachments'])->toEqual([
        ['id' => $files[0]->id, 'name' => 'IMG_2289.png', 'mime_type' => 'image/png', 'size' => 2048],
        ['id' => $files[1]->id, 'name' => 'notes.pdf', 'mime_type' => 'application/pdf', 'size' => 512],
    ]);
});

/**
 * The phone is the person, not an assistant: a `/approve` typed in the mobile
 * app has to reach the dispatcher as theirs.
 */
it('reports a reply from the mobile app as the person, via mobile', function () {
    $root = postIn($this, 'pca-agent', 'Plan for PCA-497');

    $this->withToken(mobileToken($this->dev))
        ->postJson("/api/v1/w/{$this->workspace->slug}/channels/pca-agent/messages", [
            'body' => '/approve',
            'parent_message_id' => $root->id,
        ])
        ->assertCreated();

    expect(outboundEvents(OutboundEventType::MessageCreated)->sole()->payload['actor'])->toEqual([
        'user_id' => $this->dev->id,
        'via' => 'mobile',
        'connector_id' => null,
        'is_bot' => false,
    ]);
});

it('does not export a new top-level message, even in an agent channel', function () {
    postIn($this, 'pca-agent', 'Anyone around?');

    expect(outboundEvents())->toHaveCount(0);
});

it('exports nothing from any other channel or from a direct message', function () {
    postIn($this, 'general', 'Lunch?');

    $dm = makeConversation($this->workspace, [$this->dev, $this->owner]);
    $this->postJson(route('conversations.messages.store', [$this->workspace->slug, $dm->public_id]), ['body' => 'psst'])
        ->assertCreated();

    expect(outboundEvents())->toHaveCount(0);
});

it('exports an edit and a deletion of a reply in an agent channel', function () {
    $root = makeMessage($this->agentChannel, $this->owner, 'Plan for PCA-497');
    $message = postIn($this, 'pca-agent', '/approve', $root);

    $this->patchJson(route('messages.update', [$this->workspace->slug, $message->id]), ['body' => 'actually, wait'])
        ->assertOk();

    $updated = messageEventData(OutboundEventType::MessageUpdated);

    expect($updated['message']['id'])->toBe($message->id)
        ->and($updated['message']['body'])->toBe('actually, wait')
        ->and($updated['message']['edited_at'])->not->toBeNull();

    $this->deleteJson(route('messages.destroy', [$this->workspace->slug, $message->id]))->assertSuccessful();

    expect(messageEventData(OutboundEventType::MessageDeleted))->toEqual([
        'projects' => $updated['projects'],
        'channel' => ['id' => $this->agentChannel->id, 'name' => 'pca-agent'],
        'message_id' => $message->id,
        'thread_root_id' => $root->id,
    ]);
});

it('exports edits and deletions of an agent\'s own posts, and of no other root', function () {
    $plan = makeMessage($this->agentChannel, $this->dev, 'Plan for PCA-497');
    $plan->forceFill(['metadata' => ['agent' => ['connector_id' => 'abc', 'name' => 'LocalAgent']]])->save();
    $chatter = postIn($this, 'pca-agent', 'lunch?');

    app(UpdateMessage::class)->handle($plan, 'Plan for PCA-497 (revised)');
    app(UpdateMessage::class)->handle($chatter, 'lunch at noon?');
    app(DeleteMessage::class)->handle($plan, $this->dev);
    app(DeleteMessage::class)->handle($chatter, $this->dev);

    expect(outboundEvents(OutboundEventType::MessageUpdated)->sole()->payload['data']['message']['id'])->toBe($plan->id)
        ->and(outboundEvents(OutboundEventType::MessageDeleted)->sole()->payload['data']['message_id'])->toBe($plan->id)
        ->and(outboundEvents())->toHaveCount(2);
});

it('exports no edit or deletion anywhere else', function () {
    $message = postIn($this, 'general', 'typo');

    $this->patchJson(route('messages.update', [$this->workspace->slug, $message->id]), ['body' => 'fixed'])->assertOk();
    $this->deleteJson(route('messages.destroy', [$this->workspace->slug, $message->id]))->assertSuccessful();

    expect(outboundEvents())->toHaveCount(0);
});

it('stops exporting once the only project using the channel is archived', function () {
    $root = postIn($this, 'pca-agent', 'root');
    $this->project->forceFill(['is_archived' => true])->save();

    postIn($this, 'pca-agent', 'anyone there?', $root);

    expect(outboundEvents())->toHaveCount(0);
});

it('names every live project that shares the channel', function () {
    $second = makeAgentProject($this->workspace, $this->owner, $this->agentChannel, ['name' => 'Pedi Driver App', 'key' => 'PDA']);

    postIn($this, 'pca-agent', 'hello both', postIn($this, 'pca-agent', 'root'));

    expect(collect(messageEventData(OutboundEventType::MessageCreated)['projects'])->pluck('id')->all())
        ->toBe([$this->project->id, $second->id]);
});

it('never carries a message across workspaces, whatever a project row says', function () {
    [$otherWorkspace, $otherOwner] = makeWorkspaceWithOwner();

    // A row no validation would accept, forced in to prove the gate itself
    // checks the tenant rather than trusting the settings were checked.
    makeAgentProject($otherWorkspace, $otherOwner, $this->general);

    postIn($this, 'general', 'internal only', postIn($this, 'general', 'root'));

    expect(outboundEvents())->toHaveCount(0);
});

it('stops exporting when the agent channel is cleared from the project', function () {
    $root = postIn($this, 'pca-agent', 'root');
    $this->project->forceFill(['agent_channel_id' => null])->save();

    postIn($this, 'pca-agent', 'off now', $root);

    expect(outboundEvents())->toHaveCount(0);
});

it('sends a webhook\'s post as the webhook, and does not export it as a new message', function () {
    $webhook = IncomingWebhook::factory()->forChannel($this->agentChannel)->create();
    $seen = null;

    Message::created(function () use (&$seen) {
        $seen ??= app(ActorContext::class)->current();
    });

    auth()->forgetGuards();

    $this->postJson("/hooks/{$webhook->token}", ['text' => 'CI passed on PCA-497'])->assertCreated();

    expect($seen?->toArray())->toEqual([
        'user_id' => Message::query()->sole()->sender_id,
        'via' => 'webhook',
        'connector_id' => null,
        'is_bot' => true,
    ])
        ->and(outboundEvents())->toHaveCount(0);
});

it('records the message and its event together or not at all', function () {
    try {
        app(OutboundEvents::class)->transaction([$this->workspace->id], function () {
            $root = app(SendMessage::class)->handle($this->agentChannel, $this->dev, 'root');
            app(SendMessage::class)->handle($this->agentChannel, $this->dev, 'rolled back', $root);

            throw new RuntimeException('later failure');
        });
    } catch (RuntimeException) {
    }

    expect(Message::query()->count())->toBe(0)
        ->and(OutboundEvent::query()->count())->toBe(0);
});

it('does not treat a private agent channel differently once a lead chose it', function () {
    $private = makeChannel($this->workspace, ChannelType::Private, ['name' => 'secret-agent', 'slug' => 'secret-agent']);
    addToChannel($private, $this->dev);
    $this->project->forceFill(['agent_channel_id' => $private->id])->save();

    postIn($this, 'secret-agent', 'in private', postIn($this, 'secret-agent', 'root'));

    expect(messageEventData(OutboundEventType::MessageCreated)['channel']['name'])->toBe('secret-agent');
});

describe('roots', function () {
    beforeEach(function () {
        connectAs($this->dev, $this->workspace, ConnectorKind::Agent);
        $this->agent = Actor::agent($this->dev, OAuthToken::query()->where('user_id', $this->dev->id)->latest()->firstOrFail());
    });

    it('exports the root an agent posts to start its thread', function () {
        $root = app(ActorContext::class)->using($this->agent, fn (): Message => app(SendMessage::class)->handle($this->agentChannel, $this->dev, 'Plan for PCA-497'));

        $data = messageEventData(OutboundEventType::MessageCreated);

        expect($data['message']['id'])->toBe($root->id)
            ->and($data['message']['parent_message_id'])->toBeNull()
            ->and($data['message']['thread_root_id'])->toBe($root->id)
            ->and($data['message']['agent'])->toEqual(['connector_id' => $this->agent->connectorId, 'name' => $this->agent->connectorName])
            ->and($data['projects'][0]['id'])->toBe($this->project->id)
            ->and(outboundEvents()->sole()->payload['actor']['via'])->toBe('agent');
    });

    it('does not export a root a person posts', function () {
        $this->actingAs($this->owner);

        app(SendMessage::class)->handle($this->agentChannel, $this->owner, 'Plan for PCA-497');

        expect(outboundEvents())->toHaveCount(0);
    });

    it('does not export an agent\'s root outside an agent channel', function () {
        app(ActorContext::class)->using($this->agent, fn (): Message => app(SendMessage::class)->handle($this->general, $this->dev, 'Plan for PCA-497'));

        expect(outboundEvents())->toHaveCount(0);
    });
});

/*
 * A project that moves its agent channel keeps hearing the threads its agents
 * already started for its tickets, so work under way finishes where it began;
 * new work starts in the new channel only.
 */
describe('after the agent channel moves', function () {
    beforeEach(function () {
        connectAs($this->dev, $this->workspace, ConnectorKind::Agent);
        $this->agent = Actor::agent($this->dev, OAuthToken::query()->where('user_id', $this->dev->id)->latest()->firstOrFail());
        $this->task = makeTask($this->project, $this->owner);
        $this->ticketRoot = app(ActorContext::class)->using($this->agent, fn (): Message => app(SendMessage::class)->handle(
            $this->agentChannel,
            $this->dev,
            'PCA-1: thread',
            agentContext: ['kind' => 'root', 'task_reference' => 'PCA-1', 'task_id' => $this->task->id],
        ));
        $this->otherRoot = postIn($this, 'pca-agent', 'a person\'s own thread');
        $this->moved = makeChannel($this->workspace, attributes: ['name' => 'dev-triage', 'slug' => 'dev-triage']);
        $this->project->forceFill(['agent_channel_id' => $this->moved->id])->save();
        OutboundEvent::query()->delete();
    });

    it('still exports replies, edits and deletions in a thread an agent started for a ticket', function () {
        $reply = postIn($this, 'pca-agent', '/approve', $this->ticketRoot);
        app(UpdateMessage::class)->handle($reply, '/approve please');
        app(DeleteMessage::class)->handle($reply->fresh(), $this->dev);

        $created = messageEventData(OutboundEventType::MessageCreated);
        // The deletion blanks the text of events not sent yet; the reply itself was still reported.
        expect($created['message']['id'])->toBe($reply->id)
            ->and($created['channel']['name'])->toBe('pca-agent')
            ->and($created['projects'][0]['id'])->toBe($this->project->id)
            ->and(outboundEvents(OutboundEventType::MessageUpdated))->toHaveCount(1)
            ->and(outboundEvents(OutboundEventType::MessageDeleted))->toHaveCount(1);
    });

    it('still exports the deletion of the thread\'s own first message', function () {
        app(DeleteMessage::class)->handle($this->ticketRoot->fresh(), $this->dev);

        $deleted = messageEventData(OutboundEventType::MessageDeleted);
        expect($deleted['message_id'])->toBe($this->ticketRoot->id)
            ->and($deleted['projects'][0]['id'])->toBe($this->project->id);
    });

    it('does not export other threads in the old channel, or a new root posted there', function () {
        postIn($this, 'pca-agent', 'not ticket work', $this->otherRoot);
        app(ActorContext::class)->using($this->agent, fn (): Message => app(SendMessage::class)->handle(
            $this->agentChannel,
            $this->dev,
            'PCA-1: a second thread',
            agentContext: ['kind' => 'root', 'task_reference' => 'PCA-1', 'task_id' => $this->task->id],
        ));

        expect(outboundEvents())->toHaveCount(0);
    });

    it('stops once the project turns its agents off', function () {
        $this->project->forceFill(['agent_channel_id' => null])->save();

        postIn($this, 'pca-agent', 'too late', $this->ticketRoot);

        expect(outboundEvents())->toHaveCount(0);
    });
});
