<?php

namespace Database\Factories;

use App\Models\IncomingWebhook;
use App\Models\Message;
use App\Models\WebhookThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookThread>
 */
class WebhookThreadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incoming_webhook_id' => IncomingWebhook::factory(),
            'key' => 'txn-'.fake()->unique()->numerify('######'),
            'message_id' => Message::factory(),
        ];
    }
}
