<?php

use App\Ai\Agents\Bite;
use App\Enums\WorkspaceRole;
use App\Models\Task;
use App\Services\BiteBot;
use App\Support\BiteCard;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Models\Conversation as AgentConversation;

beforeEach(function () {
    config()->set('snack.bite.enabled', true);
    config()->set('ai.providers.anthropic.key', 'test-key');

    [$this->workspace] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);

    $this->ada = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->bo = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    addToChannel($this->channel, $this->ada);
    addToChannel($this->channel, $this->bo);

    $bot = app(BiteBot::class)->accountFor($this->workspace);
    app(BiteBot::class)->joinChannel($this->channel, $bot);

    $conversation = AgentConversation::query()->create([
        'id' => (string) Str::ulid(),
        'title' => 'Approval under test',
        'participant_type' => $this->ada->getMorphClass(),
        'participant_id' => $this->ada->id,
    ]);

    $this->card = makeMessage($this->channel, $bot, 'I need your go-ahead before I create that.');
    $this->card->metadata = BiteCard::forApprovals($conversation->id, [
        new PendingApproval(
            id: 'call_abc',
            tool: 'CreateTask',
            arguments: ['project' => 'APO', 'title' => 'Fix the redirect'],
            reason: 'Create “Fix the redirect” on APO.',
        ),
    ]);
    $this->card->saveQuietly();

    $this->url = "/api/v1/w/{$this->workspace->slug}/messages/{$this->card->id}/bite";
});

it('rejects a pending action', function () {
    Bite::fake(['Alright, I have left it.']);

    $this->withToken(mobileToken($this->ada))
        ->postJson($this->url, ['decision' => 'reject'])
        ->assertOk()
        ->assertJsonPath('bite.state', 'rejected');

    expect(Task::query()->count())->toBe(0);
});

it('refuses a decision on somebody else\'s card', function () {
    Bite::fake(['Should never run.']);

    $this->withToken(mobileToken($this->bo))
        ->postJson($this->url, ['decision' => 'approve'])
        ->assertNotFound();
});
