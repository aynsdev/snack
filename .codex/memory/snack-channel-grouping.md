---
name: snack-channel-grouping
description: "Channel sidebar sections: per-user unlike project groups, assignment on the membership row, empty sections deliberately kept, and the Gate::inspect trap that turns a 404 policy into a 403."
metadata: 
  node_type: memory
  type: project
  originSessionId: e359d780-acd9-4dd3-8a8d-d871b904ad26
  modified: 2026-08-08T12:16:18.332Z
---

Channel sections (sidebar grouping), built 2026-08-07 on `feature/channel-grouping`. Mirrors [[snack-projects-decisions]]' project groups deliberately — and diverges from them in four places, each for a reason that is easy to undo by accident.

**Per user, not per workspace.** `channel_groups` carries `user_id`; a `ProjectGroup` does not. The project list is the same list for everyone, so its arrangement belongs to the workspace. The channel list is the channels *you joined*, so a shared arrangement would be headings over rooms half the workspace cannot see — and, for private channels, headings naming rooms they may not know exist. Name uniqueness is therefore per `(workspace_id, user_id)`, and `MoveChannelGroup` scopes its neighbour search by `user_id` too: two people's sections hold the same position numbers, and without it a move swaps against a stranger's heading.

**The assignment lives on `channel_members.channel_group_id`, not on the channel.** The membership row is already the per-user record (mute flag, read pointer) and exists exactly when the channel is in the sidebar. Leaving a channel unfiles it with no cleanup, and the nonsense state — a channel filed by someone not in it — is unrepresentable. The DB cannot express "this group belongs to the same user and workspace as this membership", so `AssignChannelGroupRequest` is the only thing enforcing it; two `Rule::exists` on one field is the pattern used.

**Empty sections stay; empty project groups do not.** `WorkspaceNavigator::sidebarChannelGroups()` sends every one of the viewer's groups, deliberately the opposite of `sidebarProjectGroups()`. Project groups are curated on the project *page*, so the sidebar can drop the empty ones. A channel section has no page: the sidebar is where it is made and filled, so one whose last channel just left has to stay or the reader watches their own heading vanish with nothing to put back into it. The synthetic "No section" heading is the one that is suppressed, and only when nothing above it has a heading.

**Muted channels leave their section** for the Muted shelf (the hook partitions muted out *before* bucketing). A section where four of six rows are silent is the list the reader was trying to escape; the filing survives, so unmuting puts the channel back.

**Creating a section from a channel is one request, not two.** `channel-groups.store` takes an optional `channel_id` and files it. The endpoints answer `back()`, so a client that created first could not learn the new id, and a failed second call would strand an empty section.

**The trap worth remembering:** `ChannelGroupPolicy` answers `Response::denyAsNotFound()` for another member's section — telling someone "forbidden" confirms that a particular person has a heading with that id. But a `FormRequest::authorize()` returning `bool` via `$user->can(...)` **flattens that to a 403**, silently defeating the policy. Return `Gate::inspect(...)` (an `Illuminate\Auth\Access\Response`) from `authorize()` instead — `UpdateChannelGroupRequest` and `UpdateChannelPreferencesRequest` both do. Controller-side `$this->authorize()` preserves it already.

Nothing here is written to the audit log, unlike project groups: tidying your own sidebar is not something the workspace's administrators need a feed of.

Scoped route binding needs `Workspace::channelGroups()` to exist, or `{channelGroup}` 500s with "undefined method" — see [[snack-container-abstraction]] for the sibling pattern. Query-count invariant for the sections is in `tests/Feature/Performance/QueryCountTest.php`; see [[snack-phase-7-decisions]].
