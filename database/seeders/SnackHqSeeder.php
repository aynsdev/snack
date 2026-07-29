<?php

namespace Database\Seeders;

use App\Enums\ChannelRole;
use App\Enums\ChannelType;
use App\Enums\MemberStatus;
use App\Enums\PresenceStatus;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\ChannelMember;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Builds the "Snack HQ" demo workspace for local development.
 *
 * Seeds people, roles, channels, and a few days of conversation. Threads,
 * reactions, mentions, files, and notifications are layered on by later phases
 * so that each phase stays independently runnable.
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

    /**
     * Seeded conversation, per channel.
     *
     * Written by hand rather than generated, because faker prose makes it
     * impossible to tell whether the timeline is rendering correctly — every
     * message looks like every other one. These exercise the formatting the
     * renderer supports, including a code block and a message that contains
     * angle brackets.
     *
     * @var array<string, array<int, string>>
     */
    private const SCRIPTS = [
        'general' => [
            'Morning everyone. Coffee machine on the third floor is fixed.',
            'Heroic work. Who do we thank?',
            'Facilities. I sent them a **thank you** on behalf of the whole floor.',
            "Reminder that the all-hands moved to Thursday.\n\nSame link, one day later.",
            'Is the recording going up afterwards? I have a conflict.',
            'Always does — usually within the hour.',
        ],
        'announcements' => [
            'We have signed the new office lease. Move is in October.',
            'Q3 numbers are in and they are ahead of plan. Details in the deck.',
            'Open enrolment for benefits closes at the end of the month.',
        ],
        'engineering' => [
            'Deploy is out. Watching the dashboards.',
            'Error rate looks flat, which is what we want.',
            'Found the N+1 from yesterday. It was the policy check inside the serializer.',
            'That one is always the culprit. Was it per row?',
            "Per row. Fix was to share the container instance:\n```php\n\$messages->each(fn (\$m) => \$m->setRelation('channel', \$channel));\n```",
            'One hundred and sixty queries down to eleven. Nice.',
            'I want to add a test that pins the query count so it cannot come back.',
            'Assert it is flat across page sizes rather than a fixed number — a ceiling either rots or is too loose to catch anything.',
        ],
        'product' => [
            'Discovery notes from the customer calls are written up.',
            'The theme I keep hearing is search. Everyone wants better search.',
            'Noted for the next planning round.',
        ],
        'design' => [
            'New empty states are in the file. Would love a critique.',
            'The illustration on the third one is doing a lot of work. Can we simplify?',
            'Agreed, it competes with the button. Let me try it flatter.',
        ],
        'operations' => [
            'Vendor renewals are done for the quarter.',
            'Anything we dropped?',
            'Two tools nobody had logged into since March.',
        ],
        'marketing' => [
            'Launch copy is drafted. Link in the doc.',
            'Reads well. The second paragraph could lose a sentence.',
            'Cutting it now.',
        ],
        'finance' => [
            'Close is done for the month.',
            'Anything unusual?',
            'Only the one invoice we were expecting.',
        ],
        'random' => [
            'Someone has left an entire cake in the kitchen. No note.',
            'That is either very kind or a trap.',
            'I have investigated. It is kind. It is also gone.',
            'Devastating turn of events for those of us in meetings.',
        ],
        'help' => [
            'How do I get access to the staging environment?',
            'Ask in here and someone will add you — what is your username?',
            'Sorted, you should have it now.',
            'Works. Thank you.',
            // Deliberately contains markup: it must render as characters, not
            // as an element, and it is worth seeing that with your own eyes.
            'Unrelated, but does the composer handle <script> tags safely?',
            'It does — bodies are stored as source text and rendered as text nodes, never as markup.',
        ],
        'default' => [
            'Setting this channel up now.',
            'Looks good to me.',
        ],
    ];

    public function run(): void
    {
        $admin = $this->createAdmin();
        $workspace = $this->createWorkspace($admin);

        $members = $this->createMembers($workspace);
        $channels = $this->createChannels($workspace, $admin);

        $this->populateChannels($channels, $admin, $members);
        $this->seedConversation($channels);

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
     * Fill the channels with something worth reading.
     *
     * Only the members of each channel speak in it, and messages are spread
     * back across several days so the day dividers, grouping, and unread
     * pointers all have something real to act on.
     *
     * The last-read pointer is deliberately left short of the newest message in
     * a couple of channels, so the sidebar opens with genuine unread badges
     * rather than an empty state that hides the feature.
     *
     * @param  Collection<string, Channel>  $channels
     */
    private function seedConversation(Collection $channels): void
    {
        foreach ($channels as $name => $channel) {
            $speakers = $channel->users()->get();

            if ($speakers->isEmpty()) {
                continue;
            }

            $script = self::SCRIPTS[$name] ?? self::SCRIPTS['default'];
            $posted = now()->subDays(3);
            $lastId = null;

            foreach ($script as $index => $line) {
                // Walk forward in uneven steps so some messages group into a
                // run and others fall far enough apart to stand alone.
                $posted = $posted->addMinutes(random_int(1, 220));

                $message = Message::factory()
                    ->inChannel($channel)
                    ->from($speakers[$index % $speakers->count()])
                    ->withBody($line)
                    ->create(['created_at' => $posted, 'updated_at' => $posted]);

                $lastId = $message->id;
            }

            $channel->forceFill([
                'message_count' => count($script),
                'last_message_at' => $posted,
            ])->save();

            // Everyone is caught up except in the two busiest rooms.
            if (! in_array($name, ['general', 'engineering'], true)) {
                ChannelMember::where('channel_id', $channel->id)
                    ->update(['last_read_message_id' => $lastId, 'last_read_at' => now()]);
            }
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
