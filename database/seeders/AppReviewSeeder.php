<?php

namespace Database\Seeders;

use App\Actions\Channels\CreateChannel;
use App\Actions\Channels\JoinChannel;
use App\Actions\Conversations\FindOrCreateConversation;
use App\Actions\Projects\AddProjectMembers;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\SaveTask;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\ChannelType;
use App\Enums\MemberStatus;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Equipment;
use App\Models\LeaveRequest;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\MessageBody;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds the demo workspace App Store and Play Store reviewers sign in to.
 *
 * The reviewer is an ordinary member of a workspace of their own, owned by a
 * made-up colleague, so they can try every feature without being anywhere near
 * a real team — and can delete their account, which an owner cannot.
 *
 * Meant for production, so it builds models directly and through the app's own
 * actions rather than through factories: Faker is a dev dependency and is not
 * installed there. Every run throws the previous demo workspace away and builds
 * it again, so running it before each submission resets whatever the last
 * reviewer did, along with the reviewer's password.
 *
 * Run with: php artisan db:seed --class=AppReviewSeeder --force
 */
class AppReviewSeeder extends Seeder
{
    /**
     * The colleagues the reviewer works alongside. The first owns the
     * workspace. `example.com` is reserved, so nothing sent to them leaves.
     *
     * @var array<string, array{name: string, title: string, department: string}>
     */
    private const COLLEAGUES = [
        'maya' => ['name' => 'Maya Santos', 'title' => 'Operations Lead', 'department' => 'Operations'],
        'paolo' => ['name' => 'Paolo Reyes', 'title' => 'Product Designer', 'department' => 'Design'],
        'bea' => ['name' => 'Bea Cruz', 'title' => 'Mobile Engineer', 'department' => 'Engineering'],
        'jon' => ['name' => 'Jon Villanueva', 'title' => 'Marketing Manager', 'department' => 'Marketing'],
    ];

    /**
     * Channel conversation, as [speaker, text]. `reviewer` is the account
     * signing in, and `@reviewer` in a line becomes a mention of its username.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const CHANNEL_SCRIPTS = [
        'general' => [
            ['maya', 'Good morning, team! Welcome to Snack.'],
            ['paolo', 'Morning! Coffee is fresh on the second floor.'],
            ['bea', 'The new build is on TestFlight — feedback welcome.'],
            ['jon', "Reminder: team lunch on **Friday** at 12:30.\n\nReply here if you can't make it."],
            ['maya', 'Say hi to our newest teammate, @reviewer!'],
        ],
        'announcements' => [
            ['maya', 'Office closes early on Friday for the holiday weekend.'],
            ['maya', 'Q4 planning starts next week. Agenda is in the Launch project.'],
        ],
        'design' => [
            ['paolo', 'Uploaded the new onboarding screens. Thoughts?'],
            ['bea', 'Love the illustrations. Can we make the skip button bigger?'],
            ['paolo', 'Good call, updating now.'],
        ],
        'launch' => [
            ['jon', 'Launch checklist is on the board. Pick up anything unassigned.'],
            ['bea', 'Store screenshots are done.'],
            ['maya', 'Great work everyone — we are on track.'],
        ],
    ];

    /**
     * Replies under the first message of #design, so threads have something in
     * them.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const THREAD = [
        ['jon', 'The colour on screen two really pops.'],
        ['maya', 'Agreed. Ship it!'],
    ];

    /**
     * A direct message waiting unread for the reviewer.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const DIRECT_MESSAGES = [
        ['paolo', 'Hey! Welcome aboard.'],
        ['paolo', 'Let me know if you need anything — happy to show you around.'],
    ];

    /**
     * The Launch board, as [title, column, assignee].
     *
     * @var list<array{0: string, 1: string, 2: string|null}>
     */
    private const TASKS = [
        ['Write release notes', 'To do', 'reviewer'],
        ['Prepare store screenshots', 'Done', 'bea'],
        ['Finalize onboarding copy', 'In progress', 'paolo'],
        ['Plan launch announcement', 'In progress', 'jon'],
        ['Set up support inbox', 'To do', null],
    ];

    public function __construct(
        private readonly CreateWorkspace $createWorkspace,
        private readonly CreateChannel $createChannel,
        private readonly JoinChannel $joinChannel,
        private readonly FindOrCreateConversation $findOrCreateConversation,
        private readonly CreateProject $createProject,
        private readonly AddProjectMembers $addProjectMembers,
        private readonly SaveTask $saveTask,
    ) {}

    public function run(): void
    {
        /** @var array{name: string, email: string, username: string, password: ?string, workspace: string} $config */
        $config = config('snack.mobile.app_review');

        if (blank($config['password'])) {
            throw new RuntimeException('Set SNACK_APP_REVIEW_PASSWORD before seeding the app review account.');
        }

        $people = $this->createPeople($config);

        $this->removePreviousWorkspace($config['workspace'], $people['maya']);

        $workspace = $this->createWorkspace->handle($people['maya'], [
            'name' => 'Snack Demo',
            'slug' => $config['workspace'],
            'description' => 'A sample workspace for trying Snack.',
        ]);

        $this->addMembers($workspace, $people);

        $channels = $this->createChannels($workspace, $people);

        foreach (self::CHANNEL_SCRIPTS as $channel => $script) {
            $this->postToChannel($channels[$channel], $people, $script);
        }

        $this->postThread($channels['design'], $people);
        $this->sendDirectMessages($workspace, $people);
        $this->createLaunchProject($workspace, $people);

        $this->command->info("Seeded {$workspace->name} at /w/{$workspace->slug} — sign in as {$config['email']}");
    }

    /**
     * @param  array{name: string, email: string, username: string, password: string, workspace: string}  $config
     * @return array<string, User>
     */
    private function createPeople(array $config): array
    {
        $people = [];

        foreach (self::COLLEAGUES as $key => $colleague) {
            $people[$key] = $this->upsertUser(
                email: "{$key}.demo@example.com",
                attributes: [
                    'name' => $colleague['name'],
                    'username' => "demo{$key}",
                    'title' => $colleague['title'],
                    'department' => $colleague['department'],
                ],
                // Nobody signs in as a colleague; a password nobody knows keeps
                // it that way.
                password: Str::password(32),
            );
        }

        $people['reviewer'] = $this->upsertUser(
            email: $config['email'],
            attributes: ['name' => $config['name'], 'username' => $config['username']],
            password: $config['password'],
        );

        return $people;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function upsertUser(string $email, array $attributes, string $password): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->fill([...$attributes, 'password' => $password]);
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        return $user;
    }

    /**
     * Throw away the last run's workspace, refusing to touch one somebody else
     * owns that happens to share the slug.
     */
    private function removePreviousWorkspace(string $slug, User $owner): void
    {
        $previous = Workspace::query()->where('slug', $slug)->first();

        if ($previous === null) {
            return;
        }

        if ($previous->owner_id !== $owner->id) {
            throw new RuntimeException("The workspace [{$slug}] is not the demo workspace; set SNACK_APP_REVIEW_WORKSPACE to another slug.");
        }

        DB::transaction(function () use ($previous): void {
            // Restricted rather than cascaded from their types, so Postgres
            // would refuse the workspace delete while a reviewer's leave
            // request or an equipment item still pointed at one.
            LeaveRequest::query()->where('workspace_id', $previous->id)->delete();
            Equipment::query()->where('workspace_id', $previous->id)->delete();

            $previous->delete();
        });
    }

    /**
     * Everyone joins as a member, which also files them as an employee — so the
     * reviewer can clock in and request leave — and lands them in the default
     * channels, as an accepted invitation would.
     *
     * @param  array<string, User>  $people
     */
    private function addMembers(Workspace $workspace, array $people): void
    {
        $defaults = $workspace->channels()->where('is_default', true)->get();

        foreach ($people as $key => $person) {
            if ($key === 'maya') {
                continue;
            }

            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id' => $person->id,
                'role' => WorkspaceRole::Member,
                'status' => MemberStatus::Active,
                'joined_at' => now(),
            ]);

            $person->unsetRelation('workspaceMemberships');

            $defaults->each(fn (Channel $channel) => $this->joinChannel->handle($channel, $person));
        }
    }

    /**
     * @param  array<string, User>  $people
     * @return array<string, Channel>
     */
    private function createChannels(Workspace $workspace, array $people): array
    {
        $channels = $workspace->channels()->get()->keyBy('name')->all();

        foreach (['design' => 'Critique, craft and fewer pixels.', 'launch' => 'Getting the mobile app out the door.'] as $name => $description) {
            $channels[$name] = $this->createChannel->handle($workspace, $people['maya'], [
                'name' => $name,
                'description' => $description,
                'type' => ChannelType::Public,
            ]);

            foreach ($people as $key => $person) {
                if ($key !== 'maya') {
                    $this->joinChannel->handle($channels[$name], $person);
                }
            }
        }

        return $channels;
    }

    /**
     * Written straight to the table rather than through SendMessage, so seeding
     * does not notify and push to everybody for conversation that happened
     * "yesterday".
     *
     * @param  array<string, User>  $people
     * @param  list<array{0: string, 1: string}>  $script
     */
    private function postToChannel(Channel $channel, array $people, array $script): void
    {
        $posted = now()->subDay()->setTime(9, 0);

        foreach ($script as [$speaker, $text]) {
            $posted = $posted->addMinutes(17);

            $text = str_replace('@reviewer', '@'.$people['reviewer']->username, $text);

            $this->writeMessage($people[$speaker], $text, $posted, ['workspace_id' => $channel->workspace_id, 'channel_id' => $channel->id]);
        }

        $channel->forceFill([
            'message_count' => count($script),
            'last_message_at' => $posted,
        ])->save();
    }

    /**
     * @param  array<string, User>  $people
     */
    private function postThread(Channel $channel, array $people): void
    {
        $parent = $channel->messages()->oldest('id')->firstOrFail();
        $replied = $parent->created_at;

        foreach (self::THREAD as [$speaker, $text]) {
            $replied = $replied->addMinutes(5);

            $this->writeMessage($people[$speaker], $text, $replied, [
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'parent_message_id' => $parent->id,
            ]);
        }

        $parent->forceFill([
            'reply_count' => count(self::THREAD),
            'last_reply_at' => $replied,
        ])->save();
    }

    /**
     * @param  array<string, User>  $people
     */
    private function sendDirectMessages(Workspace $workspace, array $people): void
    {
        $conversation = $this->findOrCreateConversation->handle(
            $workspace,
            $people['paolo'],
            collect([$people['reviewer']]),
        );

        $sent = now()->subHour();

        foreach (self::DIRECT_MESSAGES as [$speaker, $text]) {
            $sent = $sent->addMinute();

            $this->writeMessage($people[$speaker], $text, $sent, ['workspace_id' => $workspace->id, 'conversation_id' => $conversation->id]);
        }

        Conversation::query()->whereKey($conversation->id)->update(['last_message_at' => $sent]);
    }

    /**
     * @param  array<string, User>  $people
     */
    private function createLaunchProject(Workspace $workspace, array $people): void
    {
        $project = $this->createProject->handle($workspace, $people['maya'], [
            'name' => 'Launch',
            'key' => 'LAUNCH',
            'description' => 'Everything between here and the mobile app in the stores.',
            'uses_sprints' => false,
        ]);

        $this->addProjectMembers->handle(
            $project,
            $people['maya'],
            Collection::make($people)->except('maya')->map(fn (User $person): int => $person->id)->values()->all(),
        );

        $columns = $project->columns()->pluck('id', 'name');

        foreach (self::TASKS as [$title, $column, $assignee]) {
            $this->saveTask->create($project, $people['maya'], [
                'title' => $title,
                'board_column_id' => $columns[$column],
                'assignee_id' => $assignee === null ? null : $people[$assignee]->id,
            ]);
        }
    }

    /**
     * @param  array<string, int>  $container
     */
    private function writeMessage(User $sender, string $text, CarbonInterface $at, array $container): Message
    {
        $message = new Message([
            ...$container,
            ...MessageBody::fromInput($text)->toAttributes(),
            'sender_id' => $sender->id,
            'metadata' => [],
        ]);

        $message->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $message;
    }
}
