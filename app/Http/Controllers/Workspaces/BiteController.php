<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Bite\UpdateBiteSettings;
use App\Enums\BiteAudience;
use App\Enums\BiteCapability;
use App\Enums\BiteChannelMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bite\UpdateBiteSettingsRequest;
use App\Models\BiteMemory;
use App\Models\BiteSetting;
use App\Models\Channel;
use App\Models\Workspace;
use App\Services\BiteSummons;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The assistant's configuration screen.
 *
 * Admin-gated including the read — see {@see App\Policies\BiteSettingPolicy} for
 * why looking at the house instructions and the knowledge base is worth the same
 * rank as writing them.
 *
 * Ordinary Inertia visits throughout, like the standups screen: every action
 * here changes the page as a whole and there is nothing live on it to merge.
 */
class BiteController extends Controller
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly BiteSummons $summons,
    ) {}

    public function edit(Workspace $workspace, Request $request): Response
    {
        $this->authorize('viewAny', [BiteSetting::class, $workspace]);

        $settings = BiteSetting::forWorkspace($workspace);

        return Inertia::render('workspace/bite', [
            'settings' => [
                'is_enabled' => $settings->is_enabled,
                'audience' => $settings->audience->value,
                'channel_mode' => $settings->channel_mode->value,
                'channel_ids' => $settings->guardedChannelIds(),
                'instructions' => $settings->instructions,
                'context_messages' => $settings->context_messages,
                'max_steps' => $settings->max_steps,
                /*
                 * The resolved list rather than the stored null, so the form has
                 * one shape to render. "Never configured" is carried by
                 * `is_configured` below instead, which is the only place the
                 * difference means anything to a reader.
                 */
                'capabilities' => array_map(
                    fn (BiteCapability $capability): string => $capability->value,
                    $settings->enabledCapabilities(),
                ),
            ],

            /*
             * Whether this workspace has ever saved. The screen says so, because
             * an admin arriving at a page of sensible-looking values deserves to
             * know whether somebody chose them or they are simply the defaults.
             */
            'is_configured' => $settings->exists,

            'last_saved' => $settings->exists ? [
                'at' => $settings->updated_at?->toIso8601String(),
                'by' => $settings->editor?->name,
            ] : null,

            /*
             * The installation's own switch, so the screen can explain why
             * nothing is happening on a deployment where Bite is turned off or
             * has no provider key. Without this the workspace switch would look
             * broken: on, saved, and silent.
             */
            'is_available' => $this->summons->available(),

            'defaults' => [
                'context_messages' => (int) config('snack.bite.context_messages', 20),
                'max_steps' => (int) config('snack.bite.max_steps', 8),
            ],

            'limits' => [
                'instructions' => (int) config('snack.bite.max_instruction_characters', 2_000),
                'memory_body' => (int) config('snack.bite.knowledge.max_entry_characters', 2_000),
                'knowledge_total' => (int) config('snack.bite.knowledge.max_characters', 24_000),
            ],

            'audiences' => array_map(fn (BiteAudience $audience): array => [
                'value' => $audience->value,
                'label' => $audience->label(),
                'description' => $audience->description(),
            ], BiteAudience::cases()),

            'channel_modes' => array_map(fn (BiteChannelMode $mode): array => [
                'value' => $mode->value,
                'label' => $mode->label(),
                'uses_list' => $mode->usesList(),
            ], BiteChannelMode::cases()),

            'capabilities' => array_map(fn (BiteCapability $capability): array => [
                'value' => $capability->value,
                'label' => $capability->label(),
                'description' => $capability->description(),
                'writes' => $capability->writes(),
            ], BiteCapability::cases()),

            /*
             * The channels the guard can name: this workspace's live ones,
             * private ones included. A private channel is exactly where an admin
             * most wants to be able to say "not there", and they are being shown
             * the name rather than the contents — the same call the standups
             * screen makes.
             */
            'channels' => $workspace->channels()
                ->notArchived()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'type'])
                ->map(fn (Channel $channel): array => [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'slug' => $channel->slug,
                    'type' => $channel->type->value,
                ])
                ->all(),

            'memories' => $workspace->biteMemories()
                ->inOrder()
                ->with('author:id,name')
                ->get()
                ->map(fn (BiteMemory $memory): array => [
                    'id' => $memory->id,
                    'title' => $memory->title,
                    'body' => $memory->body,
                    'is_enabled' => $memory->is_enabled,
                    'author' => $memory->author?->name,
                    'updated_at' => $memory->updated_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function update(
        Workspace $workspace,
        UpdateBiteSettingsRequest $request,
        UpdateBiteSettings $updateSettings,
    ): RedirectResponse {
        $updateSettings->handle(
            workspace: $this->context->workspace(),
            actor: $request->user(),
            attributes: $request->settings(),
        );

        return back()->with('success', 'Bite settings saved.');
    }
}
