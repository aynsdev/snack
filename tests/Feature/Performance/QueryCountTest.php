<?php

use App\Enums\NotificationReason;
use App\Models\BoardColumn;
use App\Models\ChannelGroup;
use App\Models\ConversationParticipant;
use App\Models\Mention;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Notification;
use App\Models\ProjectGroup;
use App\Models\ProjectMember;
use App\Models\SavedMessage;
use App\Models\Task;
use App\Models\User;
use App\Services\MessageHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every list endpoint costs the same whether it returns 3 rows or 30.
 *
 * # Why an invariant rather than a budget
 *
 * Asserting a fixed ceiling ("no more than 12 queries") is the obvious way to
 * write this and the wrong one: pick it loose and a genuine N+1 slips under it,
 * pick it tight and an unrelated change to shared props breaks every test in the
 * file for no reason. Comparing two row counts asks the only question that
 * actually matters — does serving one more row cost another query? — and is
 * indifferent to whatever fixed overhead the request already carries.
 *
 * A failure here is nearly always a relation being touched during serialization
 * that nobody eager-loaded. The one that bites hardest in this codebase is
 * indirect: a resource asks the policy what the viewer may do with a row, and
 * the policy answers by consulting the row's container. See
 * {@see MessageHistory::shareContainer()} for that fix, and
 * tests/Feature/Messages/MessageHistoryTest.php for the timeline's own guard,
 * which is where this pattern comes from.
 *
 * # What is deliberately not asserted
 *
 * Wall time. It is dominated by whatever else the machine is doing and would
 * make this suite flaky the first time CI ran warm. Query count is the thing
 * that degrades silently and is worth pinning.
 */

/**
 * Queries issued while serving `$url` to `$viewer`.
 */
function queriesServing(User $viewer, string $url): int
{
    /*
     * A fresh instance for each measurement. The authenticated model persists
     * across requests within a single test and keeps whatever relations it
     * loaded, which would make the later measurement cheaper for reasons that
     * have nothing to do with how many rows were seeded.
     */
    test()->actingAs(User::findOrFail($viewer->id));

    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->get($url)->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/**
 * Assert that serving a list costs the same at 30 rows as it does at 3.
 *
 * `$prepare` receives the row count, seeds exactly that many rows — clearing
 * whatever the previous measurement left behind — and returns the URL to
 * measure.
 */
function expectConstantQueries(User $viewer, Closure $prepare): void
{
    $at = fn (int $rows): int => queriesServing($viewer, $prepare($rows));

    expect($at(30))->toBe($at(3));
}

it('serves the notification inbox in a constant number of queries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    expectConstantQueries($owner, function (int $rows) use ($workspace, $channel, $owner): string {
        Notification::query()->where('notifiable_id', $owner->id)->delete();
        $channel->messages()->forceDelete();

        foreach (range(1, $rows) as $index) {
            // A distinct message per row, so the inbox's "has this since been
            // deleted?" check has something to batch.
            $message = makeMessage($channel, $owner, "ping {$index}");

            Notification::query()->create([
                'id' => (string) Str::uuid(),
                'type' => NotificationReason::Mention->value,
                'notifiable_type' => $owner->getMorphClass(),
                'notifiable_id' => $owner->id,
                'workspace_id' => $workspace->id,
                'data' => [
                    'message_id' => $message->id,
                    'preview' => "ping {$index}",
                    'actor' => ['id' => $owner->id, 'name' => $owner->name],
                    'container' => ['type' => 'channel', 'name' => $channel->name],
                ],
            ]);
        }

        return route('notifications.index', $workspace);
    });
});

it('serves the mentions inbox in a constant number of queries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    expectConstantQueries($owner, function (int $rows) use ($workspace, $channel, $owner): string {
        Mention::query()->where('mentioned_user_id', $owner->id)->delete();
        $channel->messages()->forceDelete();

        foreach (range(1, $rows) as $index) {
            $message = makeMessage($channel, $owner, "@owner {$index}");

            Mention::factory()->create([
                'workspace_id' => $workspace->id,
                'message_id' => $message->id,
                'mentioned_user_id' => $owner->id,
            ]);
        }

        return route('mentions.index', $workspace);
    });
});

it('serves saved messages in a constant number of queries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    expectConstantQueries($owner, function (int $rows) use ($workspace, $channel, $owner): string {
        SavedMessage::query()->where('user_id', $owner->id)->delete();
        $channel->messages()->forceDelete();

        foreach (range(1, $rows) as $index) {
            $message = makeMessage($channel, $owner, "worth keeping {$index}");

            SavedMessage::factory()->create([
                'message_id' => $message->id,
                'user_id' => $owner->id,
            ]);
        }

        return route('saved.index', $workspace);
    });
});

it('serves the file browser in a constant number of queries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    expectConstantQueries($owner, function (int $rows) use ($workspace, $channel, $owner): string {
        MessageAttachment::query()->delete();
        $channel->messages()->forceDelete();

        foreach (range(1, $rows) as $index) {
            $message = makeMessage($channel, $owner, "here you go {$index}");

            MessageAttachment::factory()->create([
                'workspace_id' => $workspace->id,
                'message_id' => $message->id,
                'uploaded_by' => $owner->id,
            ]);
        }

        return route('files.index', $workspace);
    });
});

it('serves the member directory in a constant number of queries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    expectConstantQueries($owner, function (int $rows) use ($workspace, $owner): string {
        $workspace->members()->where('user_id', '!=', $owner->id)->delete();

        foreach (range(1, $rows) as $ignored) {
            joinWorkspace($workspace);
        }

        return route('members.index', $workspace);
    });
});

it('serves a thread panel in a constant number of queries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    $root = makeMessage($channel, $owner, 'the question');

    expectConstantQueries($owner, function (int $rows) use ($workspace, $channel, $owner, $root): string {
        Message::query()->where('parent_message_id', $root->id)->forceDelete();

        foreach (range(1, $rows) as $index) {
            Message::factory()
                ->inChannel($channel)
                ->from($owner)
                ->withBody("reply {$index}")
                ->create(['parent_message_id' => $root->id]);
        }

        return route('threads.show', ['workspace' => $workspace, 'message' => $root]);
    });
});

it('serves the channel list in a constant number of queries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    expectConstantQueries($owner, function (int $rows) use ($workspace, $owner): string {
        $workspace->channels()->forceDelete();

        foreach (range(1, $rows) as $ignored) {
            addToChannel(makeChannel($workspace), $owner);
        }

        return route('channels.index', $workspace);
    });
});

/**
 * The sidebar's channel sections.
 *
 * They ride on the shared props, so every workspace page pays for them — which
 * is the whole reason to pin them. A section is drawn from one row and holds no
 * channels of its own (the channels name their section, not the other way
 * round), so a person with twenty sections must cost exactly what a person with
 * one costs.
 */
it('serves the channel sections in a constant number of queries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    $membership = addToChannel($channel, $owner);

    expectConstantQueries($owner, function (int $rows) use ($workspace, $owner, $channel, $membership): string {
        $membership->update(['channel_group_id' => null]);
        ChannelGroup::query()->ownedBy($workspace, $owner)->delete();

        foreach (range(1, $rows) as $index) {
            $group = ChannelGroup::factory()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'position' => $index * 1000,
            ]);

            // The last one keeps the channel, so the filing is exercised too
            // rather than every section being empty.
            $membership->update(['channel_group_id' => $group->id]);
        }

        return route('channels.show', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]);
    });
});

/**
 * The sidebar's DM roster: one person per workspace member, each carrying the
 * direct conversation the viewer has with them.
 *
 * The member directory test above already pins the roster query itself. This
 * pins the join to the *conversations*, which is the half that reads a relation
 * per row — the roster titles and badges each thread from its participants — and
 * so is the half that turns into an N+1 the moment an eager load goes missing.
 * It rides on the shared props, so any workspace page measures it.
 */
it('serves the DM roster in a constant number of queries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();
    $channel = makeChannel($workspace);
    addToChannel($channel, $owner);

    expectConstantQueries($owner, function (int $rows) use ($workspace, $owner, $channel): string {
        ConversationParticipant::query()
            ->whereIn('conversation_id', $workspace->conversations()->select('id'))
            ->delete();
        $workspace->conversations()->delete();
        $workspace->members()->where('user_id', '!=', $owner->id)->delete();

        foreach (range(1, $rows) as $ignored) {
            makeConversation($workspace, [$owner, joinWorkspace($workspace)]);
        }

        return route('channels.show', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]);
    });
});

/**
 * The sidebar's project list, and the headings it is drawn under.
 *
 * Each project names a group, so the list reads a relation per row — exactly
 * the shape that becomes an N+1 when an eager load goes missing. It rides on
 * the shared props, so any workspace page measures it; the project index is
 * used here because it also serves the page's own copy of the list.
 */
it('serves the project list and its groups in a constant number of queries', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    expectConstantQueries($owner, function (int $rows) use ($workspace, $owner): string {
        Task::query()->whereIn('project_id', $workspace->projects()->select('id'))->delete();
        ProjectMember::query()->whereIn('project_id', $workspace->projects()->select('id'))->delete();
        BoardColumn::query()->whereIn('project_id', $workspace->projects()->select('id'))->delete();
        $workspace->projects()->delete();
        $workspace->projectGroups()->delete();

        // A group per project, which is the worst case for the headings: every
        // row carries a different one.
        foreach (range(1, $rows) as $index) {
            $group = ProjectGroup::factory()->create([
                'workspace_id' => $workspace->id,
                'position' => $index * 1000,
            ]);

            makeProject($workspace, $owner)->update(['project_group_id' => $group->id]);
        }

        return route('projects.index', $workspace);
    });
});
