<?php

namespace App\Http\Requests\Hr;

use App\Models\Announcement;
use App\Models\Channel;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AnnouncementRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): bool
    {
        $announcement = $this->route('announcement');

        return $announcement instanceof Announcement
            ? ($this->user()?->can('update', $announcement) ?? false)
            : ($this->user()?->can('create', [Announcement::class, $context->workspace()]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],

            // Under the 12,000-character message ceiling, leaving room for the
            // title, the mention, and the link the channel post adds.
            'body' => ['required', 'string', 'max:10000'],
            'is_pinned' => ['boolean'],

            /*
             * Where to post it. Only read when publishing — an existing post
             * stays where it is; moving it would mean deleting a message people
             * may already have replied to.
             *
             * The channel must be this workspace's, live, and one the author is
             * in (checked in after()): the post goes out under their name, and
             * posting into a private channel they cannot see would be a way to
             * write into a room they were never let into.
             */
            'channel_id' => [
                'nullable', 'integer',
                Rule::exists('channels', 'id')
                    ->where('workspace_id', $context->workspace()->id)
                    // A closure, so the boolean is bound as one; the plain form
                    // sends `false` as '' and PostgreSQL rejects it.
                    ->where(fn ($query) => $query->where('is_archived', false)),
            ],
            'notify_everyone' => ['boolean'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $channel = $this->channel();

                if ($channel !== null && ! $channel->hasMember($this->user())) {
                    $validator->errors()->add('channel_id', 'Join that channel before posting announcements to it.');
                }
            },
        ];
    }

    /**
     * The channel to post to, resolved from the validated id.
     */
    public function channel(): ?Channel
    {
        return $this->filled('channel_id')
            ? Channel::query()->find($this->integer('channel_id'))
            : null;
    }

    /**
     * @return array{title: string, body: string, is_pinned: bool}
     */
    public function announcementAttributes(): array
    {
        return [
            'title' => $this->string('title')->toString(),
            'body' => $this->string('body')->toString(),
            'is_pinned' => $this->boolean('is_pinned'),
        ];
    }
}
