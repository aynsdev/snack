<?php

namespace App\Actions\Workspaces;

use App\Actions\Audit\RecordAuditLog;
use App\Actions\Channels\CreateChannel;
use App\Enums\AuditAction;
use App\Enums\ChannelType;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\SlugGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Creates a workspace, its owner membership, and its default channels.
 *
 * Wrapped in a transaction: a workspace with no owner membership would be
 * unreachable by anyone, including the person who just created it.
 */
class CreateWorkspace
{
    public function __construct(
        private readonly SlugGenerator $slugs,
        private readonly CreateChannel $createChannel,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, slug?: string|null}  $attributes
     */
    public function handle(User $owner, array $attributes): Workspace
    {
        return DB::transaction(function () use ($owner, $attributes): Workspace {
            $workspace = Workspace::create([
                'owner_id' => $owner->id,
                'name' => $attributes['name'],
                'slug' => $this->slugs->forWorkspace($attributes['slug'] ?? $attributes['name']),
                'description' => $attributes['description'] ?? null,
                'member_limit' => config('snack.workspaces.member_limit'),
                'settings' => $this->defaultSettings(),
            ]);

            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'role' => WorkspaceRole::Owner,
                'status' => MemberStatus::Active,
                'joined_at' => now(),
            ]);

            // Reload so the owner membership is visible to the channel policies
            // consulted while creating the default channels.
            $owner->unsetRelation('workspaceMemberships');

            foreach (config('snack.workspaces.default_channels', []) as $index => $channel) {
                $this->createChannel->handle($workspace, $owner, [
                    'name' => $channel['name'],
                    'description' => $channel['description'] ?? null,
                    // The first default channel is where new members land.
                    'type' => $index === 0 ? ChannelType::Public : ChannelType::Announcement,
                    'is_default' => true,
                ]);
            }

            $this->audit->handle(
                workspace: $workspace,
                action: AuditAction::WorkspaceCreated,
                actor: $owner,
                auditable: $workspace,
                newValues: ['name' => $workspace->name, 'slug' => $workspace->slug],
            );

            return $workspace->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSettings(): array
    {
        return [
            'channels' => [
                'allow_self_serve_joins' => true,
            ],
            'auth' => [
                'allow_invitations' => true,
                'require_two_factor' => false,
            ],
            'uploads' => [
                'max_size_kb' => config('snack.uploads.max_size_kb'),
            ],
            'retention' => [
                // null = keep messages forever.
                'message_days' => null,
            ],
        ];
    }
}
