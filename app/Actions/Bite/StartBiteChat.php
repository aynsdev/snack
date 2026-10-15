<?php

namespace App\Actions\Bite;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BiteBot;
use Illuminate\Support\Facades\DB;

/**
 * Open a new Bite chat for one person.
 *
 * Deliberately not {@see App\Actions\Conversations\FindOrCreateConversation}:
 * that one's whole job is to *reuse* the conversation between a pair, and a
 * person and Bite are a pair. A Bite chat is always new — "New chat" that
 * reopened yesterday's would be a button that lies.
 *
 * No `ConversationStarted` either. That event exists to put a new DM into the
 * other participant's sidebar, and the other participant is a bot.
 */
class StartBiteChat
{
    public const MAX_TITLE_LENGTH = 80;

    public function __construct(private readonly BiteBot $bots) {}

    public function handle(Workspace $workspace, User $user, string $firstMessage): Conversation
    {
        $bot = $this->bots->accountFor($workspace);

        return DB::transaction(function () use ($workspace, $user, $bot, $firstMessage): Conversation {
            $conversation = Conversation::create([
                'workspace_id' => $workspace->id,
                'created_by' => $user->id,
                'type' => ConversationType::Bite,
                'name' => self::titleFrom($firstMessage),
                'participants_hash' => Conversation::hashParticipants([$user->id, $bot->id]),
            ]);

            $now = now();

            ConversationParticipant::insert(array_map(fn (int $userId): array => [
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], [$user->id, $bot->id]));

            return $conversation;
        });
    }

    /**
     * The chat's title until somebody renames it: the first line of what was
     * asked, trimmed to fit a list row.
     */
    public static function titleFrom(string $message): string
    {
        $line = str($message)->before("\n")->replaceMatches('/@bite\b/i', '')->squish()->toString();

        return $line === ''
            ? 'New chat'
            : str($line)->limit(self::MAX_TITLE_LENGTH)->toString();
    }
}
