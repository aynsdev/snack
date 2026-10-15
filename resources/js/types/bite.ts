import type { Attachment } from '@/types/snack';

/** One row of the Bite page's chat list. Mirrors BiteChatController::chatsFor. */
export type BiteChatSummary = {
    id: string;
    title: string;
    is_pinned: boolean;
    is_archived: boolean;
    share_url: string | null;
    last_message_at: string | null;
    created_at: string | null;
};

/** Mirrors App\Enums\MediaKind. */
export type MediaKind = 'image' | 'video';

/** One item in the Media tab. Mirrors BiteMediaController. */
export type BiteGeneration = {
    id: number;
    kind: MediaKind;
    model: string;
    prompt: string;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    created_at: string | null;
    attachment: Attachment | null;
    chat_id: string | null;
};

export type BiteMediaModel = {
    id: string;
    label: string;
    kind: MediaKind;
    description: string;
};

/** A live generation in the current Bite chat. */
export type BitePendingGeneration = {
    id: number;
    kind: MediaKind;
    model: string;
    prompt: string;
    status: 'pending' | 'processing';
    aspect_ratio: string | null;
};
