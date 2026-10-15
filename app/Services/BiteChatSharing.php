<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\URL;

final readonly class BiteChatSharing
{
    public function __construct(private MessageHistory $history) {}

    public function allowsFile(User $user, Message $message, string $token): bool
    {
        $chat = $message->conversation;

        return $message->parent_message_id === null
            && $chat !== null
            && $chat->type->isBite()
            && $chat->share_token !== null
            && hash_equals($chat->share_token, $token)
            && $message->id <= $chat->shared_through_message_id
            && $user->belongsToWorkspace($chat->workspace);
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, has_more: bool, next_cursor: int|null}
     */
    public function page(Conversation $chat, ?int $before = null): array
    {
        $boundary = $chat->shared_through_message_id + 1;
        $page = $this->history->page($chat, $before === null ? $boundary : min($before, $boundary));

        foreach ($page['messages'] as &$message) {
            $message['can'] = ['update' => false, 'delete' => false, 'pin' => false];
            $message['reply_count'] = 0;
            $message['bite'] = null;
            $message['is_saved'] = false;
            $files = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
            $sharedFiles = [];

            foreach ($files as $file) {
                if (! is_array($file) || ! is_int($file['id'] ?? null)) {
                    continue;
                }

                foreach (['download_url' => 'files.show', 'preview_url' => 'files.show', 'thumbnail_url' => 'files.thumbnail', 'stream_url' => 'files.play'] as $key => $route) {
                    if (! is_string($file[$key] ?? null)) {
                        continue;
                    }

                    $file[$key] = URL::temporarySignedRoute($route, now()->addMinutes(30), [
                        'attachment' => $file['id'],
                        'share' => $chat->share_token,
                        ...($key === 'preview_url' ? ['inline' => 1] : []),
                    ], absolute: false);
                }

                $sharedFiles[] = $file;
            }

            $message['attachments'] = $sharedFiles;
        }

        return $page;
    }
}
