import type { Attachment } from '@/types/snack';

/** One row of the Bite page's chat list. Mirrors BiteChatController::chatsFor. */
export type BiteChatSummary = {
    id: string;
    title: string;
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
