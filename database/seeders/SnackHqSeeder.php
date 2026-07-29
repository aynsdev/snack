<?php

namespace Database\Seeders;

use App\Enums\ChannelRole;
use App\Enums\ChannelType;
use App\Enums\MemberStatus;
use App\Enums\PresenceStatus;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Builds the "Snack HQ" demo workspace for local development.
 *
 * Phase 1 seeds people, roles, and channels. Messages, threads, reactions,
 * mentions, files, and notifications are layered on by later phases' seeders so
 * that each phase stays independently runnable.
 *
 * Every account signs in with the password "password".
 */
class SnackHqSeeder extends Seeder
{
    use WithoutModelEvents;

    private const WORKSPACE_SLUG = 'snack-hq';

    /**
     * @var array<int, array{name: string, description: string, type: ChannelType, default?: bool, department?: string}>
     */
    private const CHANNELS = [
        ['name' => 'general', 'description' => 'Company-wide chatter that does not fit anywhere else.', 'type' => ChannelType::Public, 'default' => true],
        ['name' => 'announcements', 'description' => 'Important updates from the leadership team.', 'type' => ChannelType::Announcement, 'default' => true],
        ['name' => 'engineering', 'description' => 'Shipping, breaking, and fixing things.', 'type' => ChannelType::Public, 'department' => 'Engineering'],
        ['name' => 'product', 'description' => 'Roadmap, discovery, and what we build next.', 'type' => ChannelType::Public, 'department' => 'Engineering'],
        ['name' => 'design', 'description' => 'Critique, craft, and the pursuit of fewer pixels.', 'type' => ChannelType::Public, 'department' => 'Marketing'],
        ['name' => 'operations', 'description' => 'Keeping the lights on and the plates spinning.', 'type' => ChannelType::Public, 'department' => 'Operations'],
        ['name' => 'marketing', 'description' => 'Campaigns, launches, and telling the story.', 'type' => ChannelType::Public, 'department' => 'Marketing'],
        ['name' => 'finance', 'description' => 'Budgets, invoices, and the quarterly close.', 'type' => ChannelType::Private, 'department' => 'Finance'],
        ['name' => 'random', 'description' => 'Snacks, pets, and questionable opinions about tabs.', 'type' => ChannelType::Public],
        ['name' => 'help', 'description' => 'Stuck on something? Ask here.', 'type' => ChannelType::Public],
    ];

    /**
     * @var array<int, string>
     */
    private const DEPARTMENTS = ['Engineering', 'Operations', 'Finance', 'Marketing', 'Support'];

    public function run(): void
    {
        $admin = $this->createAdmin();
        $workspace = $this->createWorkspace($admin);

        $members = $this->createMembers($workspace);
        $channels = $this->createChannels($workspace, $admin);

        $this->populateChannels($channels, $admin, $members);

        $this->command->info("Seeded {$workspace->name} at /w/{$workspace->slug} — sign in as ada@snack.test / password");
    }

    private function createAdmin(): User
    {
        return User::factory()
            ->named('Ada Lovelace', 'ada', 'ada@snack.test')
            ->online()
            ->create([
                'title' => 'Head of Engineering',
                'department' => 'Engineering',
                'timezone' => 'Europe/London',
                'status_emoji' => 'taco',
                'status_message' => 'Building Snack',
            ]);
    }

    private function createWorkspace(User $owner): Workspace
    {
        return Workspace::factory()->create([
            'owner_id' => $owner->id,
            'name' => 'Snack HQ',
            'slug' => self::WORKSPACE_SLUG,
            'description' => 'Small chats. Big work.',
        ]);
    }

    /**
     * The owner plus twenty colleagues spread across the departments, including
     * two more admins, a suspended account, and a guest — so every branch of
     * the permission model has a body behind it in development.
     *
     * @return Collection<int, User>
     */
    private function createMembers(Workspace $workspace): Collection
    {
        WorkspaceMember::factory()->owner()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $workspace->owner_id,
        ]);

        return collect(range(1, 20))
            ->map(function (int $index) use ($workspace): User {
                $department = self::DEPARTMENTS[$index % count(self::DEPARTMENTS)];

                $user = User::factory()
                    ->inDepartment($department)
                    ->when($index % 3 === 0, fn ($factory) => $factory->online())
                    ->create([
                        'presence_status' => $this->presenceFor($index),
                        'last_seen_at' => now()->subMinutes($index * 7),
                    ]);

                WorkspaceMember::factory()
                    ->when($index <= 2, fn ($factory) => $factory->admin())
                    ->when($index === 20, fn ($factory) => $factory->guest())
                    ->when($index === 19, fn ($factory) => $factory->suspended())
                    ->create([
                        'workspace_id' => $workspace->id,
                        'user_id' => $user->id,
                        'joined_at' => now()->subDays(30 - $index),
                    ]);

                return $user;
            });
    }

    private function presenceFor(int $index): PresenceStatus
    {
        return match ($index % 4) {
            0 => PresenceStatus::Online,
            1 => PresenceStatus::Away,
            2 => PresenceStatus::DoNotDisturb,
            default => PresenceStatus::Offline,
        };
    }

    /**
     * @return Collection<string, Channel>
     */
    private function createChannels(Workspace $workspace, User $creator): Collection
    {
        return collect(self::CHANNELS)
            ->mapWithKeys(function (array $definition) use ($workspace, $creator): array {
                $channel = Channel::factory()
                    ->named($definition['name'])
                    ->forWorkspace($workspace)
                    ->create([
                        'created_by' => $creator->id,
                        'description' => $definition['description'],
                        'type' => $definition['type'],
                        'is_default' => $definition['default'] ?? false,
                        'last_message_at' => now()->subMinutes(random_int(5, 2_880)),
                    ]);

                return [$definition['name'] => $channel];
            });
    }

    /**
     * Joins people to channels: everyone lands in the defaults, department
     * channels get their own people, and the open channels get a random slice
     * so the sidebar looks lived-in.
     *
     * @param  Collection<string, Channel>  $channels
     * @param  Collection<int, User>  $members
     */
    private function populateChannels(Collection $channels, User $admin, Collection $members): void
    {
        $departmentByChannel = collect(self::CHANNELS)
            ->filter(fn (array $definition): bool => isset($definition['department']))
            ->mapWithKeys(fn (array $definition): array => [$definition['name'] => $definition['department']]);

        $activeMembers = $members->take(18);

        foreach ($channels as $name => $channel) {
            // The workspace owner administers every channel they created.
            ChannelMember::factory()->channelAdmin()->create([
                'channel_id' => $channel->id,
                'user_id' => $admin->id,
            ]);

            $audience = match (true) {
                $channel->is_default => $activeMembers,
                $departmentByChannel->has($name) => $activeMembers->where('department', $departmentByChannel[$name]),
                default => $activeMembers->random(min(8, $activeMembers->count())),
            };

            foreach ($audience as $member) {
                ChannelMember::factory()->create([
                    'channel_id' => $channel->id,
                    'user_id' => $member->id,
                    'role' => ChannelRole::Member,
                    'joined_at' => now()->subDays(random_int(1, 25)),
                ]);
            }
        }

        // The guest sees exactly one channel, which is the whole point of the
        // guest role.
        $guest = $this->guestOf($members);

        if ($guest !== null) {
            ChannelMember::factory()->create([
                'channel_id' => $channels['help']->id,
                'user_id' => $guest->id,
            ]);
        }
    }

    /**
     * @param  Collection<int, User>  $members
     */
    private function guestOf(Collection $members): ?User
    {
        $guestMembership = WorkspaceMember::query()
            ->whereIn('user_id', $members->pluck('id'))
            ->where('role', WorkspaceRole::Guest)
            ->where('status', MemberStatus::Active)
            ->first();

        return $guestMembership?->user;
    }
}
