<?php

use App\Actions\Connectors\CreateConnectorToken;
use App\Ai\BiteContext;
use App\Ai\Tools\CreateTask;
use App\Enums\ConnectorKind;
use App\Enums\WorkspaceRole;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\OAuthToken;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskLabel;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;

/**
 * What a card filed by Bite carries beyond its title: the files on the message
 * that asked for it, a link back to that message, and the agent label when the
 * assignee has an agent to pick it up.
 */
beforeEach(function () {
    config()->set('snack.bite.enabled', true);
    Storage::fake('local');
    Queue::fake();

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();

    $this->ada = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->bo = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->channel = makeChannel($this->workspace, attributes: ['name' => 'general', 'slug' => 'general']);
    $this->agentChannel = makeChannel($this->workspace, attributes: ['name' => 'pca-agent', 'slug' => 'pca-agent']);

    foreach ([$this->channel, $this->agentChannel] as $channel) {
        addToChannel($channel, $this->ada);
        addToChannel($channel, $this->bo);
    }

    $this->project = makeProject($this->workspace, $this->owner, ['name' => 'Client App', 'key' => 'PCA']);
    addToProject($this->project, $this->ada);
    addToProject($this->project, $this->bo);
});

function biteAskingContext(User $user, ?Message $message = null): BiteContext
{
    return new BiteContext(
        workspace: test()->workspace,
        user: $user,
        container: test()->channel,
        message: $message,
    );
}

/**
 * A message with a real file behind each attachment, as the asker sent it.
 */
function biteMessageWithFiles(User $sender, string ...$names): Message
{
    $message = makeMessage(test()->channel, $sender, 'Checkout breaks on this screen');

    foreach ($names as $name) {
        $file = MessageAttachment::factory()->image()->create([
            'workspace_id' => test()->workspace->id,
            'message_id' => $message->id,
            'uploaded_by' => $sender->id,
            'original_name' => $name,
            'path' => "workspaces/1/attachments/2026/11/{$name}",
        ]);

        Storage::disk('local')->put($file->path, "bytes of {$name}");
        Storage::disk('local')->put((string) $file->thumbnail_path, "thumb of {$name}");
    }

    return $message->load('attachments');
}

function biteEnableAgentsOn(Project $project): void
{
    $project->forceFill(['agent_channel_id' => test()->agentChannel->id])->save();
}

function biteAgentConnectorFor(User $user): OAuthToken
{
    $issued = app(CreateConnectorToken::class)->handle(test()->workspace, $user, 'Kusina', ConnectorKind::Agent);

    return OAuthToken::query()->findOrFail($issued->getToken()->getKey());
}

function biteFileTask(User $asker, array $arguments = [], ?Message $message = null): string
{
    return (string) (new CreateTask(biteAskingContext($asker, $message)))->handle(new Request([
        'project' => 'PCA',
        'title' => 'Checkout breaks',
        ...$arguments,
    ]));
}

describe('the message that asked', function () {
    /**
     * PCA-575: filed from a message carrying IMG_2289.png, and the image never
     * reached the card.
     */
    it('copies its files onto the card, as separate objects', function () {
        $message = biteMessageWithFiles($this->ada, 'IMG_2289.png');

        $result = biteFileTask($this->ada, message: $message);

        $task = Task::query()->where('title', 'Checkout breaks')->sole();
        $copy = MessageAttachment::query()->where('task_id', $task->id)->sole();
        $original = $message->attachments->sole();

        expect($result)->toContain('Attached 1 file from their message')
            ->and($copy->original_name)->toBe('IMG_2289.png')
            ->and($copy->message_id)->toBeNull()
            ->and($copy->uploaded_by)->toBe($this->ada->id)
            ->and($copy->mime_type)->toBe($original->mime_type)
            ->and($copy->path)->not->toBe($original->path)
            ->and($copy->thumbnail_path)->not->toBe($original->thumbnail_path)
            ->and(Storage::disk('local')->get($copy->path))->toBe('bytes of IMG_2289.png')
            ->and(Storage::disk('local')->get($original->path))->toBe('bytes of IMG_2289.png')
            ->and($original->fresh()->message_id)->toBe($message->id);
    });

    it('links back to the message in the description', function () {
        $message = biteMessageWithFiles($this->ada);

        biteFileTask($this->ada, ['description' => 'Seen on iOS.'], $message);

        $description = Task::query()->where('title', 'Checkout breaks')->sole()->description;

        expect($description)->toStartWith("Seen on iOS.\n\nRequested in #general (message #{$message->id}): ")
            ->and($description)->toEndWith("/{$this->workspace->slug}/channels/general");
    });

    it('takes nothing from a message somebody else wrote', function () {
        $message = biteMessageWithFiles($this->bo, 'secret.png');

        biteFileTask($this->ada, message: $message);

        $task = Task::query()->where('title', 'Checkout breaks')->sole();

        expect(MessageAttachment::query()->where('task_id', $task->id)->exists())->toBeFalse()
            ->and((string) $task->description)->not->toContain('Requested in');
    });

    it('files without a link or files when there is no message', function () {
        biteFileTask($this->ada, ['description' => 'Plain.']);

        expect(Task::query()->where('title', 'Checkout breaks')->sole()->description)->toBe('Plain.');
    });
});

describe('the agent label', function () {
    it('labels agent when the asker files it for themselves', function () {
        biteEnableAgentsOn($this->project);
        biteAgentConnectorFor($this->ada);

        $result = biteFileTask($this->ada, ['assignee' => '@'.$this->ada->username]);

        $labels = Task::query()->where('title', 'Checkout breaks')->sole()->labels->pluck('name')->all();

        expect($labels)->toBe([TaskLabel::AGENT])
            ->and($result)->toContain('Labelled agent:');
    });

    /**
     * Never `agent` on somebody else's card: they press Start themselves.
     */
    it('asks with agent-requested when the card is somebody else\'s', function () {
        biteEnableAgentsOn($this->project);
        biteAgentConnectorFor($this->bo);

        $result = biteFileTask($this->ada, ['assignee' => '@'.$this->bo->username]);

        $labels = Task::query()->where('title', 'Checkout breaks')->sole()->labels->pluck('name')->all();

        expect($labels)->toBe([TaskLabel::AGENT_REQUESTED])
            ->and($result)->toContain('Labelled agent-requested');
    });

    it('adds no label when the assignee has no live agent', function (string $case) {
        biteEnableAgentsOn($this->project);

        if ($case === 'revoked') {
            biteAgentConnectorFor($this->bo)->forceFill(['revoked' => true])->save();
        }

        if ($case === 'mcp token') {
            app(CreateConnectorToken::class)->handle($this->workspace, $this->bo, 'Claude', ConnectorKind::Token);
        }

        $result = biteFileTask($this->ada, ['assignee' => '@'.$this->bo->username]);

        expect(Task::query()->where('title', 'Checkout breaks')->sole()->labels)->toBeEmpty()
            ->and($result)->not->toContain('Labelled');
    })->with(['none', 'revoked', 'mcp token']);

    /**
     * The person agrees to the label as well as the card.
     */
    it('says so on the approval card', function (string $who, string $expected) {
        biteEnableAgentsOn($this->project);
        biteAgentConnectorFor($this->ada);
        biteAgentConnectorFor($this->bo);

        $assignee = $who === 'self' ? $this->ada : $this->bo;

        $approval = (new CreateTask(biteAskingContext($this->ada)))->shouldRequestApproval(new Request([
            'project' => 'PCA',
            'title' => 'Checkout breaks',
            'assignee' => '@'.$assignee->username,
        ]));

        expect($approval?->reason)->toEndWith("assigned to {$assignee->name}, {$expected}.");
    })->with([
        'their own' => ['self', 'with your agent starting on it'],
        'a colleague\'s' => ['other', 'with their agent requested'],
    ]);

    it('adds no label when agents are off for the project', function () {
        biteAgentConnectorFor($this->ada);

        biteFileTask($this->ada, ['assignee' => '@'.$this->ada->username]);

        expect(Task::query()->where('title', 'Checkout breaks')->sole()->labels)->toBeEmpty();
    });

    it('adds no label to an unassigned card', function () {
        biteEnableAgentsOn($this->project);
        biteAgentConnectorFor($this->ada);

        biteFileTask($this->ada);

        expect(Task::query()->where('title', 'Checkout breaks')->sole()->labels)->toBeEmpty();
    });
});
