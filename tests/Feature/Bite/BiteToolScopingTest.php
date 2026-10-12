<?php

use App\Ai\BiteContext;
use App\Ai\BiteFiles;
use App\Ai\Tools\BrowseWorkspace;
use App\Ai\Tools\CreateTask;
use App\Ai\Tools\FindFiles;
use App\Ai\Tools\SearchMessages;
use App\Ai\Tools\SearchTasks;
use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Asset;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BiteBot;
use App\Services\MessageIndexer;
use Laravel\Ai\Tools\Request;

/**
 * The rule the whole feature rests on: Bite can only tell you what you could
 * have found yourself.
 *
 * Bite posts as a bot account that belongs to the workspace and gets added to
 * every channel it is summoned into. If its tools queried as themselves, the
 * assistant would be a search index over every private channel it had ever been
 * mentioned in, readable by anybody who typed a question. So each tool is
 * exercised here as somebody who should not see a thing, against data that
 * exists — the failure this is written to catch returns rows, not an error.
 */
beforeEach(function () {
    config()->set('snack.bite.enabled', true);
    config()->set('ai.providers.anthropic.key', 'test-key');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();

    $this->outsider = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->insider = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->open = makeChannel($this->workspace, ChannelType::Public, ['name' => 'general']);
    addToChannel($this->open, $this->outsider);
    addToChannel($this->open, $this->insider);

    $this->secret = makeChannel($this->workspace, ChannelType::Private, ['name' => 'founders']);
    addToChannel($this->secret, $this->insider);
});

/**
 * A context standing in the open channel, asking as the given person.
 */
function contextFor(User $user, ?Workspace $workspace = null): BiteContext
{
    return new BiteContext(
        workspace: $workspace ?? test()->workspace,
        user: $user,
        container: test()->open,
    );
}

/**
 * Search reads a real tsvector, so the message has to actually be indexed.
 */
function indexed(Message $message): Message
{
    app(MessageIndexer::class)->index($message->id);

    return $message;
}

it('does not surface a private channel message to someone outside it', function () {
    indexed(makeMessage($this->secret, $this->insider, 'the acquisition closes in March'));

    $tool = new SearchMessages(contextFor($this->outsider));

    $result = (string) $tool->handle(new Request(['query' => 'acquisition']));

    expect($result)->not->toContain('acquisition closes')
        ->and($result)->not->toContain('founders');
});

it('does surface it to someone inside it', function () {
    indexed(makeMessage($this->secret, $this->insider, 'the acquisition closes in March'));

    $tool = new SearchMessages(contextFor($this->insider));

    expect((string) $tool->handle(new Request(['query' => 'acquisition'])))
        ->toContain('acquisition closes');
});

it('does not list a private channel to someone outside it', function () {
    $tool = new BrowseWorkspace(contextFor($this->outsider));

    $result = (string) $tool->handle(new Request(['list' => 'channels']));

    expect($result)->toContain('#general')
        ->and($result)->not->toContain('#founders');
});

it('does not list bot accounts as people', function () {
    app(BiteBot::class)->accountFor($this->workspace);

    $tool = new BrowseWorkspace(contextFor($this->insider));

    expect((string) $tool->handle(new Request(['list' => 'people'])))
        ->not->toContain('@bite');
});

/**
 * A file's audience is the audience of the message carrying it. This is the
 * same rule the file browser applies, restated as a query — and the failure is
 * a complete index of every private channel's attachments.
 */
it('does not surface a file attached in a private channel', function () {
    $message = makeMessage($this->secret, $this->insider);

    MessageAttachment::factory()->create([
        'workspace_id' => $this->workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => $this->insider->id,
        'original_name' => 'term-sheet.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $tool = new FindFiles(contextFor($this->outsider));

    expect((string) $tool->handle(new Request(['query' => 'term-sheet'])))
        ->not->toContain('term-sheet.pdf');
});

it('refuses to resolve a file the asker cannot read', function () {
    $message = makeMessage($this->secret, $this->insider);

    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $this->workspace->id,
        'message_id' => $message->id,
        'uploaded_by' => $this->insider->id,
    ]);

    $files = app(BiteFiles::class);

    expect($files->resolve(contextFor($this->outsider), $attachment->id))->toBeNull()
        ->and($files->resolve(contextFor($this->insider), $attachment->id)?->id)->toBe($attachment->id);
});

/**
 * The id in a tool call is a number a language model produced. Treating it as
 * trustworthy would let a question in one company's workspace name a file in
 * another's.
 */
it('refuses to resolve a file belonging to another workspace', function () {
    [$other, $stranger] = makeWorkspaceWithOwner();
    $otherChannel = makeChannel($other);
    $message = makeMessage($otherChannel, $stranger);

    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $other->id,
        'message_id' => $message->id,
        'uploaded_by' => $stranger->id,
    ]);

    expect(app(BiteFiles::class)->resolve(contextFor($this->insider), $attachment->id))->toBeNull();
});

it('refuses to resolve an upload nobody has sent yet', function () {
    $attachment = MessageAttachment::factory()->create([
        'workspace_id' => $this->workspace->id,
        'message_id' => null,
        'uploaded_by' => $this->insider->id,
    ]);

    expect(app(BiteFiles::class)->resolve(contextFor($this->insider), $attachment->id))->toBeNull();
});

/**
 * The library has no private shelves, so every member sees all of it — except
 * a guest, who is a contractor or a customer and for whom the library is where
 * the unsigned contract and the price list live.
 */
it('hides the asset library from a guest', function () {
    $guest = joinWorkspace($this->workspace, null, WorkspaceRole::Guest);
    addToChannel($this->open, $guest);

    $asset = Asset::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Master services agreement',
    ]);

    MessageAttachment::factory()->create([
        'workspace_id' => $this->workspace->id,
        'message_id' => null,
        'asset_id' => $asset->id,
        'uploaded_by' => $this->owner->id,
        'original_name' => 'msa.pdf',
    ]);

    $forGuest = (string) (new FindFiles(contextFor($guest)))->handle(new Request(['query' => 'agreement']));
    $forMember = (string) (new FindFiles(contextFor($this->insider)))->handle(new Request(['query' => 'agreement']));

    expect($forGuest)->not->toContain('Master services agreement')
        ->and($forMember)->toContain('Master services agreement');
});

it('hides projects and tasks from a guest', function () {
    $guest = joinWorkspace($this->workspace, null, WorkspaceRole::Guest);
    $project = makeProject($this->workspace, $this->owner, ['name' => 'Apollo', 'key' => 'APO']);
    makeTask($project, $this->owner, ['title' => 'Ship the thing']);

    $context = contextFor($guest);

    expect((string) (new BrowseWorkspace($context))->handle(new Request(['list' => 'projects'])))
        ->not->toContain('Apollo')
        ->and((string) (new SearchTasks($context))->handle(new Request(['query' => 'Ship'])))
        ->not->toContain('Ship the thing');
});

/**
 * Approval is consent, not authority. The policy is what decides, and it is
 * checked against the person who asked rather than against Bite — which is a
 * member of the workspace and would otherwise sail through.
 */
it('refuses to create a task on a project the asker cannot contribute to', function () {
    // A live board the asker is simply not on. The policy is what refuses, and
    // it is asked about them rather than about Bite — which is a member of the
    // workspace and would otherwise sail through.
    makeProject($this->workspace, $this->owner, ['name' => 'Apollo', 'key' => 'APO']);

    $tool = new CreateTask(contextFor($this->insider));

    $result = (string) $tool->handle(new Request([
        'project' => 'APO',
        'title' => 'Something',
    ]));

    expect($result)->toContain('cannot create tasks')
        ->and(Task::query()->where('title', 'Something')->exists())->toBeFalse();
});

/**
 * An archived board is not somewhere to put new work, and it is refused before
 * the policy rather than by it — so the answer says the board is not there
 * rather than talking about permissions the person does have.
 */
it('does not offer an archived project at all', function () {
    $archived = makeProject($this->workspace, $this->owner, ['name' => 'Apollo', 'key' => 'APO']);
    addToProject($archived, $this->insider);
    $archived->update(['is_archived' => true]);

    // A live board beside it, so the answer is the list of real options rather
    // than "there is nothing here" — which is what the model needs to ask with.
    makeProject($this->workspace, $this->owner, ['name' => 'Gemini', 'key' => 'GEM']);

    $result = (string) (new CreateTask(contextFor($this->insider)))->handle(new Request([
        'project' => 'APO',
        'title' => 'Something',
    ]));

    expect($result)->toContain('No project here is called')
        ->and($result)->toContain('GEM · Gemini')
        ->and($result)->not->toContain('Apollo')
        ->and(Task::query()->where('title', 'Something')->exists())->toBeFalse();
});

it('refuses to create a task on another workspace\'s project', function () {
    [$other, $stranger] = makeWorkspaceWithOwner();
    makeProject($other, $stranger, ['name' => 'Elsewhere', 'key' => 'ELS']);

    $tool = new CreateTask(contextFor($this->insider));

    $result = (string) $tool->handle(new Request([
        'project' => 'ELS',
        'title' => 'Something',
    ]));

    expect($result)->toContain('no projects in this workspace')
        ->and(Task::query()->where('title', 'Something')->exists())->toBeFalse();
});

/**
 * The card is a confirmation of something specific, so the tool has to name
 * that something. A generic "the assistant wants to run a tool" is a dialog
 * everybody clicks through without reading.
 */
it('names what it is about to create in the approval reason', function () {
    $tool = new CreateTask(contextFor($this->insider));

    $approval = $tool->shouldRequestApproval(new Request([
        'project' => 'apo',
        'title' => 'Fix the signup redirect',
    ]));

    expect($approval)->not->toBeNull()
        ->and($approval->reason)->toContain('Fix the signup redirect')
        ->and($approval->reason)->toContain('APO');
});

it('files the task under the person who asked, not under Bite', function () {
    $project = makeProject($this->workspace, $this->owner, ['name' => 'Apollo', 'key' => 'APO']);
    addToProject($project, $this->insider);

    $tool = new CreateTask(contextFor($this->insider));

    $result = (string) $tool->handle(new Request([
        'project' => 'APO',
        'title' => 'Fix the signup redirect',
        'type' => 'bug',
        'priority' => 'high',
    ]));

    $task = Task::query()->where('title', 'Fix the signup redirect')->first();

    expect($result)->toContain($task->reference())
        ->and($task->reporter_id)->toBe($this->insider->id)
        ->and($task->type->value)->toBe('bug')
        ->and($task->priority->value)->toBe('high');
});

/**
 * A model that invents a type or a priority must not be able to write one into
 * the column — the enums are what the board renders from.
 */
it('falls back to sane defaults when the model invents a type or priority', function () {
    $project = makeProject($this->workspace, $this->owner, ['name' => 'Apollo', 'key' => 'APO']);
    addToProject($project, $this->insider);

    (new CreateTask(contextFor($this->insider)))->handle(new Request([
        'project' => 'APO',
        'title' => 'Odd one',
        'type' => 'catastrophe',
        'priority' => 'extremely urgent',
    ]));

    $task = Task::query()->where('title', 'Odd one')->first();

    expect($task->type->value)->toBe('task')
        ->and($task->priority->value)->toBe('medium');
});

/**
 * A limit the model chose is still a number from outside. Left unclamped, one
 * confident request for five hundred rows is a context-window overflow and a
 * bill.
 */
it('clamps a limit the model asked for', function () {
    foreach (range(1, 30) as $index) {
        indexed(makeMessage($this->open, $this->insider, "budget note number {$index}"));
    }

    $tool = new SearchMessages(contextFor($this->insider));

    $result = (string) $tool->handle(new Request(['query' => 'budget', 'limit' => 500]));

    expect(substr_count($result, 'message #'))->toBeLessThanOrEqual(25);
});

/**
 * # Naming a project and a person the way people actually do
 *
 * "@bite file an issue on the marketing board and give it to Ada" is the shape
 * of request this tool exists for, and until these it only understood
 * "APO"-style keys and exact usernames.
 */
it('finds a project by its name, not only its key', function () {
    $project = makeProject($this->workspace, $this->owner, ['name' => 'Apollo', 'key' => 'APO']);
    addToProject($project, $this->insider);

    (new CreateTask(contextFor($this->insider)))->handle(new Request([
        'project' => 'apollo',
        'title' => 'Named by name',
    ]));

    expect(Task::query()->where('title', 'Named by name')->first()?->project_id)
        ->toBe($project->id);
});

it('resolves a partial name when only one project could be meant', function () {
    $project = makeProject($this->workspace, $this->owner, ['name' => 'Marketing site', 'key' => 'MKT']);
    addToProject($project, $this->insider);

    (new CreateTask(contextFor($this->insider)))->handle(new Request([
        'project' => 'marketing',
        'title' => 'Half a name',
    ]));

    expect(Task::query()->where('title', 'Half a name')->first()?->project_id)
        ->toBe($project->id);
});

/**
 * Two boards that both match is the case where guessing does real damage — the
 * card lands on the wrong team's backlog and nobody notices for a week. It asks
 * instead, and is given the options to ask with.
 */
it('refuses to guess between two projects that both match', function () {
    $first = makeProject($this->workspace, $this->owner, ['name' => 'Marketing site', 'key' => 'MKT']);
    $second = makeProject($this->workspace, $this->owner, ['name' => 'Marketing emails', 'key' => 'MEM']);

    addToProject($first, $this->insider);
    addToProject($second, $this->insider);

    $result = (string) (new CreateTask(contextFor($this->insider)))->handle(new Request([
        'project' => 'marketing',
        'title' => 'Ambiguous',
    ]));

    expect($result)->toContain('do not guess')
        ->and($result)->toContain('MKT · Marketing site')
        ->and($result)->toContain('MEM · Marketing emails')
        ->and(Task::query()->where('title', 'Ambiguous')->exists())->toBeFalse();
});

it('assigns by username', function () {
    $project = makeProject($this->workspace, $this->owner, ['name' => 'Apollo', 'key' => 'APO']);
    addToProject($project, $this->insider);
    addToProject($project, $this->outsider);

    $result = (string) (new CreateTask(contextFor($this->insider)))->handle(new Request([
        'project' => 'APO',
        'title' => 'For a colleague',
        'assignee' => '@'.$this->outsider->username,
    ]));

    expect(Task::query()->where('title', 'For a colleague')->first()?->assignee_id)
        ->toBe($this->outsider->id)
        ->and($result)->toContain('Assigned to');
});

it('assigns by display name too', function () {
    $project = makeProject($this->workspace, $this->owner, ['name' => 'Apollo', 'key' => 'APO']);
    addToProject($project, $this->insider);
    addToProject($project, $this->outsider);

    (new CreateTask(contextFor($this->insider)))->handle(new Request([
        'project' => 'APO',
        'title' => 'By their name',
        'assignee' => $this->outsider->name,
    ]));

    expect(Task::query()->where('title', 'By their name')->first()?->assignee_id)
        ->toBe($this->outsider->id);
});

/**
 * The bug this half was written for. Membership is the assignment gate all
 * through Snack, so a colleague who is not on the board cannot be given the
 * card — but silently filing it unassigned left Bite free to reply "assigned to
 * Bob", and Bob never heard about it.
 */
it('files the task and says so when the assignee is not on the project', function () {
    $project = makeProject($this->workspace, $this->owner, ['name' => 'Apollo', 'key' => 'APO']);
    addToProject($project, $this->insider);

    $result = (string) (new CreateTask(contextFor($this->insider)))->handle(new Request([
        'project' => 'APO',
        'title' => 'Filed but unassigned',
        'assignee' => $this->outsider->username,
    ]));

    $task = Task::query()->where('title', 'Filed but unassigned')->first();

    expect($task)->not->toBeNull()
        ->and($task->assignee_id)->toBeNull()
        ->and($result)->toContain('could NOT be assigned')
        ->and($result)->toContain('not a member of Apollo');
});

it('says plainly when nobody by that name is here', function () {
    $project = makeProject($this->workspace, $this->owner, ['name' => 'Apollo', 'key' => 'APO']);
    addToProject($project, $this->insider);

    $result = (string) (new CreateTask(contextFor($this->insider)))->handle(new Request([
        'project' => 'APO',
        'title' => 'Nobody home',
        'assignee' => 'santa',
    ]));

    expect($result)->toContain('nobody in this workspace matches');
});

/**
 * The card is the person's one chance to catch the model having picked the
 * wrong board or the wrong colleague, so it names both — resolved, not echoed.
 */
it('names the resolved project and assignee on the approval card', function () {
    $project = makeProject($this->workspace, $this->owner, ['name' => 'Apollo', 'key' => 'APO']);
    addToProject($project, $this->insider);
    addToProject($project, $this->outsider);

    $approval = (new CreateTask(contextFor($this->insider)))->shouldRequestApproval(new Request([
        'project' => 'apollo',
        'title' => 'Fix the signup redirect',
        'assignee' => $this->outsider->username,
    ]));

    expect($approval->reason)
        ->toContain('Apollo (APO)')
        ->toContain('assigned to '.$this->outsider->name);
});
