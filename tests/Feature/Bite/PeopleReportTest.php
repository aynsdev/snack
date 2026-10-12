<?php

use App\Ai\Agents\Bite;
use App\Ai\BiteAttachments;
use App\Ai\BiteContext;
use App\Ai\Tools\PeopleReport;
use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\User;
use App\Services\BiteBot;
use Laravel\Ai\Tools\Request;

/**
 * Attendance and output figures are about people, not for them. The figures
 * have to be right, but the property worth most here is who gets them, and
 * where: an admin, in a DM with Bite alone — never a member, never a channel,
 * never a DM a colleague can read.
 */
beforeEach(function () {
    config()->set('snack.bite.enabled', true);
    config()->set('ai.providers.anthropic.key', 'test-key');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();

    $this->bot = app(BiteBot::class)->accountFor($this->workspace);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->ada = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->ada->update(['name' => 'Ada Lovelace', 'username' => 'ada']);
    $this->grace = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    $this->grace->update(['name' => 'Grace Hopper', 'username' => 'grace']);

    $this->adaEmployee = $this->ada->employeeIn($this->workspace);
    $this->graceEmployee = $this->grace->employeeIn($this->workspace);
});

function reportContext(User $asker, Channel|Conversation $container): BiteContext
{
    return new BiteContext(workspace: test()->workspace->fresh(), user: $asker, container: $container);
}

function privateDm(User $asker): Conversation
{
    return makeConversation(test()->workspace, [$asker, test()->bot]);
}

function clockIn(Employee $employee, string $in, string $out, array $minutes = []): void
{
    $employee->timeEntries()->create([
        'workspace_id' => $employee->workspace_id,
        'clock_in_at' => $in,
        'clock_out_at' => $out,
        ...$minutes,
    ]);
}

function peopleReport(BiteContext $context, array $arguments): string
{
    return (string) (new PeopleReport($context))->handle(new Request($arguments));
}

it('ranks employees by the figure asked about', function () {
    $this->adaEmployee->absences()->create(['workspace_id' => $this->workspace->id, 'date' => '2026-09-03', 'type' => 'sick']);
    $this->graceEmployee->absences()->create(['workspace_id' => $this->workspace->id, 'date' => '2026-09-04', 'type' => 'unexcused']);
    $this->graceEmployee->absences()->create(['workspace_id' => $this->workspace->id, 'date' => '2026-09-05', 'type' => 'sick']);

    $result = peopleReport(reportContext($this->owner, privateDm($this->owner)), [
        'from' => '2026-09-01', 'to' => '2026-09-30', 'sort_by' => 'absences',
    ]);

    expect($result)->toContain('Ranked by absences')
        ->and($result)->toContain('2 absences (')
        ->and($result)->toContain('1 unexcused')
        ->and($result)->toContain('1 absence (1 sick)')
        ->and(strpos($result, 'Grace Hopper'))->toBeLessThan(strpos($result, 'Ada Lovelace'));
});

it('reports lateness, undertime, overtime, and hours from time entries', function () {
    clockIn($this->adaEmployee, '2026-09-01 09:20:00', '2026-09-01 17:00:00', ['late_minutes' => 20, 'early_out_minutes' => 60, 'overtime_minutes' => 0]);
    clockIn($this->adaEmployee, '2026-09-02 09:00:00', '2026-09-02 19:00:00', ['late_minutes' => 0, 'early_out_minutes' => 0, 'overtime_minutes' => 60]);

    $result = peopleReport(reportContext($this->owner, privateDm($this->owner)), [
        'from' => '2026-09-01', 'to' => '2026-09-30', 'person' => 'ada',
    ]);

    expect($result)->toContain('2 days, 17.7 h worked')
        ->and($result)->toContain('late 1× (20 min)')
        ->and($result)->toContain('undertime 1× (60 min)')
        ->and($result)->toContain('overtime 60 min')
        ->and($result)->not->toContain('Grace Hopper');
});

it('counts tasks completed in the period and those overdue now', function () {
    $project = makeProject($this->workspace, $this->owner);
    makeTask($project, $this->owner, ['assignee_id' => $this->ada->id, 'completed_at' => '2026-09-10 12:00:00', 'story_points' => 5]);
    makeTask($project, $this->owner, ['assignee_id' => $this->ada->id, 'completed_at' => '2026-08-10 12:00:00', 'story_points' => 8]);
    makeTask($project, $this->owner, ['assignee_id' => $this->ada->id, 'due_on' => now()->subWeek()->toDateString()]);

    $result = peopleReport(reportContext($this->owner, privateDm($this->owner)), [
        'from' => '2026-09-01', 'to' => '2026-09-30', 'person' => 'ada',
    ]);

    expect($result)->toContain('tasks: 1 completed (5 points), 1 overdue now')
        ->and($result)->toContain('no performance reviews');
});

it('leaves out employees from other workspaces', function () {
    [$elsewhere] = makeWorkspaceWithOwner();
    $stranger = joinWorkspace($elsewhere, null, WorkspaceRole::Member);
    $stranger->update(['name' => 'Someone Else']);
    $stranger->employeeIn($elsewhere);

    $result = peopleReport(reportContext($this->owner, privateDm($this->owner)), []);

    expect($result)->not->toContain('Someone Else')
        ->and($result)->toContain('Showing 4 of 4 employees');
});

it('is built for an admin in a private DM with Bite', function () {
    $agent = new Bite(reportContext($this->owner, privateDm($this->owner)), $this->bot, app(BiteAttachments::class));

    expect(collect($agent->tools())->contains(fn ($tool): bool => $tool instanceof PeopleReport))->toBeTrue();
});

it('is not built where the answer would not stay private', function (Closure $where) {
    [$asker, $container] = $where();

    $agent = new Bite(reportContext($asker, $container), $this->bot, app(BiteAttachments::class));

    expect(collect($agent->tools())->contains(fn ($tool): bool => $tool instanceof PeopleReport))->toBeFalse()
        ->and(peopleReport(reportContext($asker, $container), []))->toContain('only available to workspace admins')
        ->and(peopleReport(reportContext($asker, $container), []))->not->toContain('Ada Lovelace');
})->with([
    'a member, even in a DM with Bite' => fn () => [test()->member, privateDm(test()->member)],
    'an admin in a channel' => fn () => [test()->owner, makeChannel(test()->workspace, ChannelType::Public, ['name' => 'general'])],
    'an admin in a DM with a colleague' => fn () => [test()->owner, makeConversation(test()->workspace, [test()->owner, test()->member])],
    'an admin in a group DM with Bite' => fn () => [test()->owner, makeConversation(test()->workspace, [test()->owner, test()->bot, test()->member], 'ops')],
]);
