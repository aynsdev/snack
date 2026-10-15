<?php

namespace App\Http\Requests\Bite;

use App\Actions\Bite\StartBiteChat;
use App\Models\Conversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateBiteChatRequest extends FormRequest
{
    public function authorize(): Response
    {
        $conversation = $this->route('conversation');
        abort_unless($conversation instanceof Conversation, 404);

        return Gate::inspect('delete', $conversation);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:'.StartBiteChat::MAX_TITLE_LENGTH],
            'is_pinned' => ['sometimes', 'required', 'boolean'],
            'is_archived' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function chatAttributes(): array
    {
        $data = $this->validated();
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = trim($data['name']);
        }
        if (array_key_exists('is_pinned', $data)) {
            $attributes['is_pinned'] = $this->boolean('is_pinned');
        }
        if (array_key_exists('is_archived', $data)) {
            $attributes['archived_at'] = $this->boolean('is_archived') ? now() : null;
        }

        return $attributes;
    }
}
