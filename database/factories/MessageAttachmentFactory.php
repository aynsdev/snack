<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MessageAttachment>
 */
class MessageAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->word().'.pdf';

        return [
            'workspace_id' => Workspace::factory(),
            'message_id' => Message::factory(),
            'uploaded_by' => User::factory(),
            'disk' => 'local',
            'path' => 'attachments/'.Str::uuid()->toString(),
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1_024, 5_000_000),
            'metadata' => [],
        ];
    }

    public function image(): static
    {
        return $this->state(fn (): array => [
            'original_name' => fake()->word().'.png',
            'mime_type' => 'image/png',
            'thumbnail_path' => 'attachments/thumbnails/'.Str::uuid()->toString(),
            'metadata' => ['width' => 1200, 'height' => 800],
        ]);
    }

    /**
     * Uploaded but never attached to a sent message.
     */
    public function orphaned(): static
    {
        return $this->state(fn (): array => ['message_id' => null]);
    }
}
