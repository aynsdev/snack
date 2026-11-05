<?php

use App\Actions\Connectors\CreateConnectorToken;
use App\Enums\ChannelType;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Mcp\Servers\SnackServer;
use App\Mcp\Tools\BrowseWorkspaceTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\ReadChannelTool;
use App\Mcp\Tools\ReadThreadTool;
use App\Mcp\Tools\SendMessageTool;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * The MCP server: an AI client acting as the person who created its token,
 * inside the one workspace the token was created for, and never able to see
 * more than that person could by clicking.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();

    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->channel = makeChannel($this->workspace, ChannelType::Public, ['name' => 'general', 'slug' => 'general']);
    addToChannel($this->channel, $this->member);
});

describe('the HTTP endpoint', function () {
    it('lists the tools for a connector token', function () {
        [, $token] = connectAs($this->member, $this->workspace);

        $this->withToken($token)
            ->postJson('/mcp', mcpCall('tools/list'))
            ->assertOk()
            ->assertSee('search_messages')
            ->assertSee('send_message');
    });

    it('refuses a request with no token', function () {
        $this->postJson('/mcp', mcpCall('tools/list'))->assertUnauthorized();
    });

    it('refuses a token without the MCP scope', function () {
        // A token minted for anything else must not open this door by accident.
        app(CreateConnectorToken::class)->handle($this->workspace, $this->member, 'warm up the client');
        $token = $this->member->createToken('something else')->accessToken;

        $this->withToken($token)
            ->postJson('/mcp', mcpCall('tools/list'))
            ->assertForbidden();
    });

    it('tells an unauthenticated client where to sign in', function () {
        $this->postJson('/mcp', mcpCall('tools/list'))
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    });

    it('refuses a browser session', function () {
        $this->actingAs($this->member)
            ->postJson('/mcp', mcpCall('tools/list'))
            ->assertUnauthorized();
    });

    it('stops working the moment its owner is suspended', function () {
        [, $token] = connectAs($this->member, $this->workspace);

        WorkspaceMember::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('user_id', $this->member->id)
            ->update(['status' => MemberStatus::Suspended]);

        $this->withToken($token)
            ->postJson('/mcp', mcpCall('tools/list'))
            ->assertForbidden();
    });
});

describe('reading', function () {
    it('reads a channel the person can see', function () {
        makeMessage($this->channel, $this->owner, 'Ship it on Friday');
        [$user] = connectAs($this->member, $this->workspace);

        SnackServer::actingAs($user)
            ->tool(ReadChannelTool::class, ['channel' => '#general'])
            ->assertOk()
            ->assertSee('Ship it on Friday');
    });

    /**
     * By id as well as name: `read_file` takes the id, and a client told only
     * the name has no way to open the file.
     */
    it('names a message\'s files with the id read_file takes', function () {
        $message = makeMessage($this->channel, $this->owner, 'Here is the bug');
        $file = MessageAttachment::factory()->image()->create([
            'workspace_id' => $this->workspace->id,
            'message_id' => $message->id,
            'uploaded_by' => $this->owner->id,
            'original_name' => 'IMG_2289.png',
        ]);
        [$user] = connectAs($this->member, $this->workspace);

        SnackServer::actingAs($user)
            ->tool(ReadChannelTool::class, ['channel' => '#general'])
            ->assertOk()
            ->assertSee("files: IMG_2289.png (file {$file->id})");
    });

    it('treats a private channel the person is not in as missing', function () {
        $private = makeChannel($this->workspace, ChannelType::Private, ['name' => 'founders', 'slug' => 'founders']);
        makeMessage($private, $this->owner, 'The layoff plan');
        [$user] = connectAs($this->member, $this->workspace);

        SnackServer::actingAs($user)
            ->tool(ReadChannelTool::class, ['channel' => 'founders'])
            ->assertHasErrors()
            ->assertDontSee('The layoff plan');
    });

    it('cannot reach a channel in another workspace', function () {
        [$elsewhere, $otherOwner] = makeWorkspaceWithOwner();
        joinWorkspace($elsewhere, $this->member);
        $other = makeChannel($elsewhere, ChannelType::Public, ['name' => 'general', 'slug' => 'general']);
        makeMessage($other, $otherOwner, 'Other tenant secret');

        // The token is for $this->workspace; the other #general is out of reach
        // even though the person is a member there too.
        [$user] = connectAs($this->member, $this->workspace);

        SnackServer::actingAs($user)
            ->tool(ReadChannelTool::class, ['channel' => 'general'])
            ->assertDontSee('Other tenant secret');
    });

    it('reads a thread from any message in it', function () {
        $root = makeMessage($this->channel, $this->owner, 'Which database?');
        Message::factory()->create([
            'workspace_id' => $this->workspace->id,
            'channel_id' => $this->channel->id,
            'sender_id' => $this->member->id,
            'parent_message_id' => $root->id,
            'body' => 'Postgres, obviously',
        ]);
        [$user] = connectAs($this->member, $this->workspace);

        SnackServer::actingAs($user)
            ->tool(ReadThreadTool::class, ['message_id' => $root->id])
            ->assertOk()
            ->assertSee(['Which database?', 'Postgres, obviously']);
    });

    it('will not open somebody else\'s direct message', function () {
        $conversation = makeConversation($this->workspace, [$this->owner, joinWorkspace($this->workspace)]);
        $dm = makeConversationMessage($conversation, $this->owner, 'Just between us');
        [$user] = connectAs($this->member, $this->workspace);

        SnackServer::actingAs($user)
            ->tool(ReadThreadTool::class, ['message_id' => $dm->id])
            ->assertHasErrors()
            ->assertDontSee('Just between us');
    });

    it('delegates to the Bite tools, as the token owner', function () {
        makeChannel($this->workspace, ChannelType::Private, ['name' => 'founders', 'slug' => 'founders']);
        [$user] = connectAs($this->member, $this->workspace);

        SnackServer::actingAs($user)
            ->tool(BrowseWorkspaceTool::class, ['list' => 'channels'])
            ->assertOk()
            ->assertSee('#general')
            ->assertDontSee('#founders');
    });
});

describe('writing', function () {
    it('posts a message under the person\'s own name', function () {
        [$user] = connectAs($this->member, $this->workspace);

        SnackServer::actingAs($user)
            ->tool(SendMessageTool::class, ['channel' => 'general', 'body' => 'Posted from Claude'])
            ->assertOk()
            ->assertSee('Posted message');

        $message = Message::query()->latest('id')->firstOrFail();

        expect($message->body)->toContain('Posted from Claude')
            ->and($message->sender_id)->toBe($this->member->id)
            ->and($message->channel_id)->toBe($this->channel->id);
    });

    it('replies in a thread', function () {
        $root = makeMessage($this->channel, $this->owner, 'Thoughts?');
        [$user] = connectAs($this->member, $this->workspace);

        SnackServer::actingAs($user)
            ->tool(SendMessageTool::class, ['channel' => 'general', 'body' => 'Looks good', 'reply_to' => $root->id])
            ->assertOk();

        expect($root->fresh()->reply_count)->toBe(1);
    });

    it('will not post in a channel the person has not joined', function () {
        $other = makeChannel($this->workspace, ChannelType::Public, ['name' => 'random', 'slug' => 'random']);
        [$user] = connectAs($this->member, $this->workspace);

        SnackServer::actingAs($user)
            ->tool(SendMessageTool::class, ['channel' => 'random', 'body' => 'Hello'])
            ->assertHasErrors(['Join this channel to post in it.']);

        expect($other->messages()->count())->toBe(0);
    });

    it('files a task with the person as the reporter', function () {
        $project = makeProject($this->workspace, $this->owner, ['name' => 'Engineering', 'key' => 'ENG']);
        addToProject($project, $this->member);
        [$user] = connectAs($this->member, $this->workspace);

        SnackServer::actingAs($user)
            ->tool(CreateTaskTool::class, ['project' => 'ENG', 'title' => 'Fix the login redirect'])
            ->assertOk()
            ->assertSee('Created');

        $task = Task::query()->where('title', 'Fix the login redirect')->firstOrFail();

        expect($task->reporter_id)->toBe($this->member->id)
            ->and($task->project_id)->toBe($project->id);
    });
});
