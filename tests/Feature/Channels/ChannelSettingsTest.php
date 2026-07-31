<?php

use App\Actions\Workspaces\AcceptWorkspaceInvitation;
use App\Actions\Workspaces\InviteWorkspaceMember;
use App\Enums\AuditAction;
use App\Enums\ChannelRole;
use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;

/**
 * A channel's shared settings: renaming, re-typing, archiving, and restoring.
 *
 * Every one of these is gated on ChannelPolicy::update or ::archive, and every
 * one leaves an audit entry — which is what the last four assertions are really
 * about.
 */

/**
 * A channel whose admin is an ordinary workspace member, so the tests can tell
 * channel-admin rights apart from workspace-admin rights.
 *
 * @return array{0: Workspace, 1: User, 2: Channel}
 */
function channelWithAdmin(ChannelType $type = ChannelType::Public): array
{
    [$workspace] = makeWorkspaceWithOwner();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Member);

    // Name *and* slug, because the factory generates them independently — and
    // half of what these tests assert is that the two stay in step.
    $channel = makeChannel($workspace, $type, ['name' => 'design', 'slug' => 'design']);
    addToChannel($channel, $admin, ChannelRole::Admin);

    return [$workspace, $admin, $channel];
}

it('renames a channel and moves its slug with it', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();

    $this->actingAs($admin)
        ->patch(route('channels.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['name' => 'Product Design'])
        // The slug follows the name, so the redirect has to be to the new one —
        // sending the person back would leave them on a dead URL.
        ->assertRedirect(route('channels.show', [
            'workspace' => $workspace->slug,
            'channel' => 'product-design',
        ]));

    $fresh = $channel->fresh();

    expect($fresh->name)->toBe('Product Design')
        ->and($fresh->slug)->toBe('product-design');
});

it('does not walk the slug when the name is unchanged', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();

    // Without SlugGenerator's ignoreId the channel's own row is the collision,
    // and saving the form untouched would rename it to design-2.
    $this->actingAs($admin)
        ->patch(route('channels.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['name' => 'design', 'topic' => 'Type and spacing']);

    expect($channel->fresh()->slug)->toBe('design');
});

it('leaves fields the form did not submit alone', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();

    $channel->forceFill(['description' => 'Where design happens'])->save();

    $this->actingAs($admin)
        ->patch(route('channels.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['topic' => 'Shipping the new nav']);

    expect($channel->fresh()->description)->toBe('Where design happens');
});

it('records what the channel was called before', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();

    $this->actingAs($admin)
        ->patch(route('channels.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['name' => 'Product Design']);

    $entry = AuditLog::query()->whereAction(AuditAction::ChannelUpdated)->sole();

    // "Who renamed #design and what was it called before?" is the question the
    // entry exists to answer, so both sides are recorded.
    expect($entry->user_id)->toBe($admin->id)
        ->and($entry->old_values['name'])->toBe('design')
        ->and($entry->new_values['name'])->toBe('Product Design');
});

it('writes no audit entry when nothing changed', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();

    // Somebody opened the form and saved it. An entry for that is noise in the
    // one log that has to stay readable.
    $this->actingAs($admin)
        ->patch(route('channels.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['name' => $channel->name]);

    expect(AuditLog::query()->whereAction(AuditAction::ChannelUpdated)->count())->toBe(0);
});

it('refuses an ordinary member editing a channel they do not administer', function () {
    [$workspace, , $channel] = channelWithAdmin();
    $member = joinWorkspace($workspace, role: WorkspaceRole::Member);
    addToChannel($channel, $member);

    $this->actingAs($member)
        ->patch(route('channels.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['name' => 'Hijacked'])
        ->assertForbidden();
});

it('lets a workspace admin edit a channel they are not a member of', function () {
    [$workspace, , $channel] = channelWithAdmin();
    $admin = joinWorkspace($workspace, role: WorkspaceRole::Admin);

    $this->actingAs($admin)
        ->patch(route('channels.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['topic' => 'Set by an admin'])
        ->assertRedirect();

    expect($channel->fresh()->topic)->toBe('Set by an admin');
});

it('stops a channel admin turning their channel into an announcement channel', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();

    /*
     * An announcement channel silences everyone but workspace admins, so a
     * channel admin who is an ordinary member must not be able to reach for it.
     */
    $this->actingAs($admin)
        ->patch(route('channels.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['type' => ChannelType::Announcement->value])
        ->assertSessionHasErrors('type');

    expect($channel->fresh()->type)->toBe(ChannelType::Public);
});

it('rejects a reserved channel name', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();

    $this->actingAs($admin)
        ->patch(route('channels.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['name' => 'everyone'])
        ->assertSessionHasErrors('name');
});

it('archives a channel without removing anyone or anything', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();
    $message = makeMessage($channel, $admin, 'still here afterwards');

    $this->actingAs($admin)
        ->post(route('channels.archive.store', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertRedirect(route('channels.index', ['workspace' => $workspace->slug]));

    expect($channel->fresh()->is_archived)->toBeTrue()
        // The whole difference between archiving and deleting.
        ->and($channel->members()->count())->toBe(1)
        ->and($message->fresh())->not->toBeNull()
        ->and(AuditLog::query()->whereAction(AuditAction::ChannelArchived)->count())->toBe(1);
});

it('restores an archived channel', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();
    $channel->forceFill(['is_archived' => true])->save();

    /*
     * ChannelPolicy::archive deliberately does not route through `update`,
     * which refuses archived channels — doing so would make an archived channel
     * permanently unarchivable.
     */
    $this->actingAs($admin)
        ->delete(route('channels.archive.destroy', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertRedirect();

    expect($channel->fresh()->is_archived)->toBeFalse()
        ->and(AuditLog::query()->whereAction(AuditAction::ChannelUnarchived)->count())->toBe(1);
});

it('refuses to edit an archived channel', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();
    $channel->forceFill(['is_archived' => true])->save();

    $this->actingAs($admin)
        ->patch(route('channels.update', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]), ['name' => 'Renamed while closed'])
        ->assertForbidden();
});

it('refuses to archive a default channel', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();
    $channel->forceFill(['is_default' => true])->save();

    // A workspace whose #general has been archived has no landing channel for
    // anyone joining it.
    $this->actingAs($admin)
        ->post(route('channels.archive.store', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]))
        ->assertForbidden();
});

it('archives idempotently', function () {
    [$workspace, $admin, $channel] = channelWithAdmin();

    foreach (range(1, 2) as $ignored) {
        $this->actingAs($admin)->post(route('channels.archive.store', [
            'workspace' => $workspace->slug,
            'channel' => $channel->slug,
        ]));
    }

    // A double-submitted form must not leave two entries in the trail — nor,
    // had this been a toggle, quietly restore the channel it was meant to close.
    expect($channel->fresh()->is_archived)->toBeTrue()
        ->and(AuditLog::query()->whereAction(AuditAction::ChannelArchived)->count())->toBe(1);
});

it('records joining and leaving a channel', function () {
    [$workspace] = makeWorkspaceWithOwner();
    $member = joinWorkspace($workspace, role: WorkspaceRole::Member);
    $channel = makeChannel($workspace);

    $this->actingAs($member)->post(route('channels.membership.store', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]));

    $this->actingAs($member)->delete(route('channels.membership.destroy', [
        'workspace' => $workspace->slug,
        'channel' => $channel->slug,
    ]));

    expect(AuditLog::query()->whereAction(AuditAction::ChannelMemberAdded)->count())->toBe(1)
        ->and(AuditLog::query()->whereAction(AuditAction::ChannelMemberRemoved)->count())->toBe(1);
});

it('does not log the automatic joins that come with accepting an invitation', function () {
    [$workspace, $owner] = makeWorkspaceWithOwner();

    Channel::factory()->forWorkspace($workspace)->create([
        'name' => 'general',
        'is_default' => true,
    ]);

    $newcomer = User::factory()->create();

    $invitation = app(InviteWorkspaceMember::class)
        ->handle($workspace, $owner, $newcomer->email, WorkspaceRole::Member);

    app(AcceptWorkspaceInvitation::class)->handle($invitation, $newcomer);

    /*
     * Being auto-enrolled in #general is already said once by `member.joined`.
     * An entry per default channel per person would bury the deliberate
     * membership changes underneath it — which is why the trail is written in
     * the controller rather than inside JoinChannel.
     */
    expect(AuditLog::query()->whereAction(AuditAction::ChannelMemberAdded)->count())->toBe(0)
        ->and(AuditLog::query()->whereAction(AuditAction::MemberJoined)->count())->toBe(1);
});
