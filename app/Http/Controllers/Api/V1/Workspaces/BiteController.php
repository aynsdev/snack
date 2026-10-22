<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

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
use Illuminate\Http\JsonResponse;

/**
 * The assistant's configuration — the mirror of
 * {@see \App\Http\Controllers\Workspaces\BiteController}. Admin-gated including
 * the read: the payload carries the house instructions and the whole knowledge
 * base. A save answers with the screen as it now stands.
 */
class BiteController extends Controller
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly BiteSummons $summons,
    ) {}

    public function edit(Workspace $workspace): JsonResponse
    {
        $this->authorize('viewAny', [BiteSetting::class, $workspace]);

        return $this->screen($workspace);
    }

    public function update(
        Workspace $workspace,
        UpdateBiteSettingsRequest $request,
        UpdateBiteSettings $updateSettings,
    ): JsonResponse {
        $updateSettings->handle(
            workspace: $this->context->workspace(),
            actor: $request->user(),
            attributes: $request->settings(),
        );

        // Re-read: the relation may have been loaded as null before the save.
        return $this->screen($this->context->workspace()->unsetRelation('biteSetting'));
    }

    /**
     * The web page's props, key for key. `is_configured` tells saved values
     * from defaults; `is_available` is the installation's own switch.
     */
    private function screen(Workspace $workspace): JsonResponse
    {
        $settings = BiteSetting::forWorkspace($workspace);

        return response()->json([
            'data' => [
                'settings' => [
                    'is_enabled' => $settings->is_enabled,
                    'audience' => $settings->audience->value,
                    'channel_mode' => $settings->channel_mode->value,
                    'channel_ids' => $settings->guardedChannelIds(),
                    'instructions' => $settings->instructions,
                    'context_messages' => $settings->context_messages,
                    'max_steps' => $settings->max_steps,
                    'capabilities' => array_map(
                        fn (BiteCapability $capability): string => $capability->value,
                        $settings->enabledCapabilities(),
                    ),
                ],
                'is_configured' => $settings->exists,
                'last_saved' => $settings->exists ? [
                    'at' => $settings->updated_at?->toIso8601String(),
                    'by' => $settings->editor?->name,
                ] : null,
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
                    ->map(fn (BiteMemory $memory): array => BiteMemoryController::present($memory))
                    ->all(),
            ],
        ]);
    }
}
