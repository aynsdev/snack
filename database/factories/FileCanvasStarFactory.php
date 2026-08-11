<?php

namespace Database\Factories;

use App\Models\Canvas;
use App\Models\FileCanvasStar;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileCanvasStar>
 */
class FileCanvasStarFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'canvas_id' => Canvas::factory(),
            'workspace_id' => fn (array $attributes): int => Canvas::query()
                ->findOrFail($attributes['canvas_id'])
                ->workspace_id,
            'user_id' => User::factory(),
            'message_attachment_id' => null,
        ];
    }

    public function forCanvas(Canvas $canvas, User $user): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $canvas->workspace_id,
            'user_id' => $user->id,
            'canvas_id' => $canvas->id,
            'message_attachment_id' => null,
        ]);
    }

    public function forFile(MessageAttachment $attachment, User $user): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $attachment->workspace_id,
            'user_id' => $user->id,
            'canvas_id' => null,
            'message_attachment_id' => $attachment->id,
        ]);
    }
}
