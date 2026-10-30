<?php

use App\Enums\ChannelType;
use App\Enums\ConnectorKind;
use App\Enums\WorkspaceRole;
use App\Models\MessageAttachment;
use App\Services\MessageIndexer;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What an agent connector may read when the daemon names who the answer is
 * for (`as_seen_by`) and where it will be posted (`audience_channel_id`): only
 * what every viewer can see, and nothing the audience could not.
 */
beforeEach(function () {
    Queue::fake();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->summoner = joinWorkspace($this->workspace);
    $this->bystander = joinWorkspace($this->workspace);

    $this->general = makeChannel($this->workspace, ChannelType::Public, ['name' => 'general', 'slug' => 'general']);
    $this->room = makeChannel($this->workspace, ChannelType::Public, ['name' => 'agent-room', 'slug' => 'agent-room']);
    $this->founders = makeChannel($this->workspace, ChannelType::Private, ['name' => 'founders', 'slug' => 'founders']);
    $this->shared = makeChannel($this->workspace, ChannelType::Private, ['name' => 'shared', 'slug' => 'shared']);
    $this->solo = makeChannel($this->workspace, ChannelType::Private, ['name' => 'solo', 'slug' => 'solo']);

    foreach ([$this->owner, $this->summoner, $this->bystander] as $person) {
        addToChannel($this->room, $person);
    }
    addToChannel($this->founders, $this->owner);
    addToChannel($this->shared, $this->owner);
    addToChannel($this->shared, $this->summoner);
    addToChannel($this->solo, $this->owner);

    $this->publicPost = makeMessage($this->general, $this->bystander, 'Public roadmap');
    $this->roomPost = makeMessage($this->room, $this->summoner, 'Room question');
    $this->foundersPost = makeMessage($this->founders, $this->owner, 'Founders layoffs');
    $this->sharedPost = makeMessage($this->shared, $this->summoner, 'Shared pricing');
    $this->dmPost = makeConversationMessage(makeConversation($this->workspace, [$this->owner, $this->summoner]), $this->owner, 'Direct salary');

    [, $this->token] = connectAs($this->owner, $this->workspace, ConnectorKind::Agent);
    auth()->forgetGuards();
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array{text: string, error: bool}
 */
function scopedRead(TestCase $test, string $tool, array $arguments, ?string $token = null): array
{
    $response = $test->withToken($token ?? $test->token)
        ->postJson('/mcp', mcpCall('tools/call', ['name' => $tool, 'arguments' => $arguments]))
        ->assertOk();

    return ['text' => (string) $response->json('result.content.0.text'), 'error' => $response->json('result.isError') === true];
}

describe('owner as the only viewer', function () {
    it('reads the owner\'s private channels and direct messages', function () {
        expect(scopedRead($this, 'read_thread', ['message_id' => $this->foundersPost->id, 'as_seen_by' => []]))
            ->toMatchArray(['error' => false])
            ->and(scopedRead($this, 'get_message', ['message_id' => $this->dmPost->id, 'as_seen_by' => [$this->owner->id]])['text'])
            ->toContain('Direct salary');
    });

    it('lifts the audience rule for a private channel only the viewers are in', function () {
        $read = scopedRead($this, 'read_thread', ['message_id' => $this->dmPost->id, 'as_seen_by' => [], 'audience_channel_id' => $this->solo->id]);

        expect($read['error'])->toBeFalse()->and($read['text'])->toContain('Direct salary');
    });
});

describe('a task run posting into its agent channel', function () {
    beforeEach(function () {
        $this->agentChannel = makeChannel($this->workspace, ChannelType::Private, ['name' => 'pca-agent', 'slug' => 'pca-agent']);
        addToChannel($this->agentChannel, $this->owner);
        addToChannel($this->agentChannel, $this->bystander);
        $this->agentPost = makeMessage($this->agentChannel, $this->bystander, 'Agent channel plan');
        $this->taskRun = ['as_seen_by' => [], 'audience_channel_id' => $this->agentChannel->id];
    });

    it('cannot read the owner\'s direct messages', function () {
        $read = scopedRead($this, 'get_message', ['message_id' => $this->dmPost->id, ...$this->taskRun]);

        expect($read['error'])->toBeTrue()->and($read['text'])->not->toContain('Direct salary');
    });

    it('reads a private channel only when it is the agent channel itself', function () {
        expect(scopedRead($this, 'read_thread', ['message_id' => $this->agentPost->id, ...$this->taskRun])['text'])->toContain('Agent channel plan')
            ->and(scopedRead($this, 'read_thread', ['message_id' => $this->foundersPost->id, ...$this->taskRun])['error'])->toBeTrue()
            ->and(scopedRead($this, 'read_channel', ['channel' => 'shared', ...$this->taskRun])['error'])->toBeTrue()
            ->and(scopedRead($this, 'read_thread', ['message_id' => $this->publicPost->id, ...$this->taskRun])['error'])->toBeFalse();
    });
});

describe('a summoner who is not the owner', function () {
    beforeEach(function () {
        $this->asSummoner = ['as_seen_by' => [$this->summoner->id]];
    });

    it('reads only what both people can see', function () {
        expect(scopedRead($this, 'read_thread', ['message_id' => $this->sharedPost->id, ...$this->asSummoner])['text'])->toContain('Shared pricing')
            ->and(scopedRead($this, 'read_thread', ['message_id' => $this->foundersPost->id, ...$this->asSummoner]))
            ->toMatchArray(['error' => true])
            ->and(scopedRead($this, 'read_channel', ['channel' => 'founders', ...$this->asSummoner])['error'])->toBeTrue();
    });

    it('never reads direct messages, even ones the summoner is in', function () {
        $read = scopedRead($this, 'get_message', ['message_id' => $this->dmPost->id, ...$this->asSummoner]);

        expect($read['error'])->toBeTrue()->and($read['text'])->not->toContain('Direct salary');
    });

    it('limits search to what both can see', function () {
        app(MessageIndexer::class)->backfill(all: true);

        $text = scopedRead($this, 'search_messages', ['query' => 'layoffs OR salary OR pricing OR roadmap', ...$this->asSummoner])['text'];

        expect($text)->toContain('Shared pricing')
            ->and($text)->toContain('Public roadmap')
            ->and($text)->not->toContain('Founders layoffs')
            ->and($text)->not->toContain('Direct salary');
    });

    it('lists only channels both can see', function () {
        $text = scopedRead($this, 'browse_workspace', ['list' => 'channels', ...$this->asSummoner])['text'];

        expect($text)->toContain('#shared')->and($text)->not->toContain('#founders');
    });
});

describe('an audience channel', function () {
    beforeEach(function () {
        $this->inRoom = ['as_seen_by' => [$this->summoner->id], 'audience_channel_id' => $this->room->id];
    });

    it('allows public channels and the audience channel itself, and nothing private elsewhere', function () {
        expect(scopedRead($this, 'read_thread', ['message_id' => $this->publicPost->id, ...$this->inRoom])['error'])->toBeFalse()
            ->and(scopedRead($this, 'read_thread', ['message_id' => $this->roomPost->id, ...$this->inRoom])['error'])->toBeFalse()
            ->and(scopedRead($this, 'read_thread', ['message_id' => $this->sharedPost->id, ...$this->inRoom])['error'])->toBeTrue();
    });

    it('applies to the owner\'s own conversations too', function () {
        $asOwner = ['as_seen_by' => [], 'audience_channel_id' => $this->room->id];

        expect(scopedRead($this, 'read_thread', ['message_id' => $this->foundersPost->id, ...$asOwner])['error'])->toBeTrue()
            ->and(scopedRead($this, 'get_message', ['message_id' => $this->dmPost->id, ...$asOwner])['error'])->toBeTrue();
    });

    it('keeps public channels out when a guest is in the audience', function () {
        addToChannel($this->room, joinWorkspace($this->workspace, null, WorkspaceRole::Guest));

        expect(scopedRead($this, 'read_thread', ['message_id' => $this->publicPost->id, ...$this->inRoom])['error'])->toBeTrue()
            ->and(scopedRead($this, 'read_thread', ['message_id' => $this->roomPost->id, ...$this->inRoom])['error'])->toBeFalse();
    });

    it('lets a private audience channel answer about itself', function () {
        $read = scopedRead($this, 'read_channel', ['channel' => 'shared', 'as_seen_by' => [$this->summoner->id], 'audience_channel_id' => $this->shared->id]);

        expect($read['text'])->toContain('Shared pricing');
    });

    it('scopes file reads to where the file was shared', function () {
        $file = MessageAttachment::factory()->create([
            'workspace_id' => $this->workspace->id, 'message_id' => $this->sharedPost->id, 'uploaded_by' => $this->summoner->id,
        ]);

        expect(scopedRead($this, 'read_file', ['file_id' => $file->id, ...$this->inRoom])['error'])->toBeTrue()
            ->and(scopedRead($this, 'read_file', ['file_id' => $file->id, 'as_seen_by' => [$this->summoner->id]])['text'])
            ->not->toContain('There is no file');
    });
});

describe('tasks', function () {
    beforeEach(function () {
        $this->project = makeProject($this->workspace, $this->owner, ['name' => 'Pedi Client App', 'key' => 'PCA']);
        $this->task = makeTask($this->project, $this->owner, ['title' => 'Fix add()']);
        $this->guest = joinWorkspace($this->workspace, null, WorkspaceRole::Guest);
    });

    it('opens a task on any project both people can see', function () {
        expect(scopedRead($this, 'get_task', ['task' => $this->task->reference(), 'as_seen_by' => [$this->summoner->id]])['text'])
            ->toContain('Fix add()');
    });

    it('hides tasks the summoner could not open', function () {
        $asGuest = ['as_seen_by' => [$this->guest->id]];

        expect(scopedRead($this, 'get_task', ['task' => $this->task->reference(), ...$asGuest])['error'])->toBeTrue()
            ->and(scopedRead($this, 'list_tasks', ['project' => 'PCA', ...$asGuest])['error'])->toBeTrue()
            ->and(scopedRead($this, 'search_tasks', ['query' => 'Fix', ...$asGuest])['text'])->not->toContain('Fix add()');

        addToProject($this->project, $this->guest);

        expect(scopedRead($this, 'get_task', ['task' => $this->task->reference(), ...$asGuest])['text'])->toContain('Fix add()');
    });
});

describe('browse_workspace', function () {
    it('withholds the member directory when a guest is a viewer or in the audience', function () {
        $guest = joinWorkspace($this->workspace, null, WorkspaceRole::Guest);
        $listing = fn (array $scope): array => scopedRead($this, 'browse_workspace', ['list' => 'people', ...$scope]);

        expect($listing(['as_seen_by' => [$guest->id]])['text'])->not->toContain($this->bystander->username)
            ->and($listing(['as_seen_by' => [$guest->id]])['text'])->toContain('cannot browse the member directory');

        addToChannel($this->room, $guest);

        expect($listing(['as_seen_by' => [$this->summoner->id], 'audience_channel_id' => $this->room->id])['text'])->not->toContain($this->bystander->username)
            ->and($listing(['as_seen_by' => [$this->summoner->id]])['text'])->toContain($this->bystander->username);
    });

    it('keeps channels and projects to what a guest viewer may see', function () {
        $guest = joinWorkspace($this->workspace, null, WorkspaceRole::Guest);
        addToChannel($this->room, $guest);
        makeProject($this->workspace, $this->owner, ['name' => 'Pedi Client App', 'key' => 'PCA']);

        $channels = scopedRead($this, 'browse_workspace', ['list' => 'channels', 'as_seen_by' => [$guest->id]])['text'];

        expect($channels)->toContain('#agent-room')
            ->and($channels)->not->toContain('#general')
            ->and(scopedRead($this, 'browse_workspace', ['list' => 'projects', 'as_seen_by' => [$guest->id]])['text'])->not->toContain('PCA');
    });
});

describe('validation', function () {
    it('refuses viewers who are not active members of the workspace', function () {
        [, $outsider] = makeWorkspaceWithOwner();

        $read = scopedRead($this, 'read_thread', ['message_id' => $this->publicPost->id, 'as_seen_by' => [$outsider->id]]);

        expect($read['error'])->toBeTrue()->and($read['text'])->toContain('as_seen_by');
    });

    it('refuses malformed viewers and an audience the viewers cannot see', function () {
        expect(scopedRead($this, 'read_thread', ['message_id' => $this->publicPost->id, 'as_seen_by' => 'everyone'])['error'])->toBeTrue()
            ->and(scopedRead($this, 'read_thread', ['message_id' => $this->publicPost->id, 'as_seen_by' => [$this->summoner->id], 'audience_channel_id' => $this->founders->id])['text'])
            ->toContain('audience_channel_id');
    });

    it('refuses the parameters from a connector that is not an agent', function () {
        [, $token] = connectAs($this->owner, $this->workspace);
        auth()->forgetGuards();

        $read = scopedRead($this, 'read_thread', ['message_id' => $this->foundersPost->id, 'as_seen_by' => [$this->summoner->id]], $token);

        expect($read['error'])->toBeTrue()
            ->and($read['text'])->toContain('only from agent connectors')
            ->and($read['text'])->not->toContain('Founders layoffs');
    });

    it('refuses them on a tool that does not apply them', function () {
        $read = scopedRead($this, 'find_files', ['query' => 'x', 'as_seen_by' => [$this->summoner->id]]);

        expect($read['error'])->toBeTrue();
    });
});

describe('get_message', function () {
    it('shows the message with its neighbours', function () {
        makeMessage($this->general, $this->bystander, 'Earlier note');
        $target = makeMessage($this->general, $this->bystander, 'The decision');
        makeMessage($this->general, $this->bystander, 'Later note');

        $text = scopedRead($this, 'get_message', ['message_id' => $target->id, 'around' => 1])['text'];

        expect($text)->toContain('Message in #general')
            ->and($text)->toContain('Earlier note')
            ->and($text)->toContain(">>> message #{$target->id}")
            ->and($text)->toContain('Later note')
            ->and($text)->not->toContain('Public roadmap');
    });
});
