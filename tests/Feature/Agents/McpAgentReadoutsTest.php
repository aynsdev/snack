<?php

use App\Enums\ChannelType;
use App\Enums\ColumnCategory;
use App\Models\Message;

/**
 * What the agent daemon reads back from Snack to check the dispatcher's word:
 * whether a thread message was edited or posted by an agent, and where a
 * project's agents are pointed right now.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->dev = joinWorkspace($this->workspace);

    $this->channel = makeChannel($this->workspace, attributes: ['name' => 'pca-agent', 'slug' => 'pca-agent']);
    addToChannel($this->channel, $this->dev);
    addToChannel($this->channel, $this->owner);

    [, $this->token] = connectAs($this->dev, $this->workspace);
    auth()->forgetGuards();
});

function mcpText(object $test, string $tool, array $arguments): string
{
    return $test->withToken($test->token)
        ->postJson('/mcp', mcpCall('tools/call', ['name' => $tool, 'arguments' => $arguments]))
        ->assertOk()
        ->json('result.content.0.text');
}

function headerOf(Message $message): string
{
    $message->loadMissing('sender');

    return "message #{$message->id} — {$message->sender->name} (@{$message->sender->username}), {$message->created_at->toDayDateTimeString()}";
}

describe('read_thread', function () {
    it('marks edited replies and agent replies, and leaves plain ones exactly as they were', function () {
        $root = makeMessage($this->channel, $this->dev, 'Plan for PCA-497');
        $reply = fn (array $attributes): Message => Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'channel_id' => $this->channel->id,
            'sender_id' => $this->owner->id,
            'parent_message_id' => $root->id,
            ...$attributes,
        ]);

        $plain = $reply(['body' => 'Looks fine']);
        $edited = $reply(['body' => '/approve', 'edited_at' => now()]);
        $agent = $reply(['body' => 'Plan (updated)', 'metadata' => ['agent' => ['connector_id' => 'abc', 'name' => 'LocalAgent']]]);
        $both = $reply(['body' => 'Plan (again)', 'edited_at' => now(), 'metadata' => ['agent' => ['connector_id' => 'abc', 'name' => 'LocalAgent']]]);

        $text = mcpText($this, 'read_thread', ['message_id' => $root->id]);

        expect($text)->toContain(headerOf($plain)."\n  Looks fine")
            ->and($text)->toContain(headerOf($edited)." · edited\n  /approve")
            ->and($text)->toContain(headerOf($agent)." · via agent\n  Plan (updated)")
            ->and($text)->toContain(headerOf($both)." · edited · via agent\n  Plan (again)");
    });

    it('puts the markers before the reply count on a root', function () {
        $root = makeMessage($this->channel, $this->dev, 'Plan');
        $root->forceFill(['edited_at' => now(), 'reply_count' => 2])->save();

        expect(mcpText($this, 'read_channel', ['channel' => 'pca-agent']))
            ->toContain(headerOf($root)." · edited · 2 replies\n  Plan");
    });
});

describe('get_project', function () {
    beforeEach(function () {
        $this->project = makeAgentProject($this->workspace, $this->owner, $this->channel, ['name' => 'Pedi Client App', 'key' => 'PCA']);
        addToProject($this->project, $this->dev);
    });

    it('names the agent channel and columns', function () {
        $started = projectColumn($this->project, ColumnCategory::Started);
        $done = projectColumn($this->project, ColumnCategory::Done);
        $this->project->forceFill([
            'agent_in_progress_column_id' => $started->id,
            'agent_review_column_id' => $done->id,
        ])->save();

        expect(mcpText($this, 'get_project', ['project' => 'PCA']))
            ->toContain("Agents: #pca-agent (in progress: {$started->name}, review: {$done->name})");
    });

    it('says which columns are not set', function () {
        expect(mcpText($this, 'get_project', ['project' => 'PCA']))
            ->toContain('Agents: #pca-agent (in progress: not set, review: not set)');
    });

    it('says off when there is no channel, or the project or channel is archived', function (string $how) {
        match ($how) {
            'no channel' => $this->project->forceFill(['agent_channel_id' => null])->save(),
            'archived project' => $this->project->forceFill(['is_archived' => true])->save(),
            'archived channel' => $this->channel->forceFill(['is_archived' => true])->save(),
        };

        $text = mcpText($this, 'get_project', ['project' => 'PCA']);

        expect($text)->toContain('Agents: off')
            ->and($text)->not->toContain('#pca-agent');
    })->with(['no channel', 'archived project', 'archived channel']);

    it('does not name a channel the caller cannot see', function () {
        $secret = makeChannel($this->workspace, ChannelType::Private, ['name' => 'founders-agent']);
        addToChannel($secret, $this->owner);
        $this->project->forceFill(['agent_channel_id' => $secret->id])->save();

        $text = mcpText($this, 'get_project', ['project' => 'PCA']);

        expect($text)->toContain('Agents: on')
            ->and($text)->not->toContain('founders-agent')
            ->and($text)->not->toContain('not visible');
    });
});
