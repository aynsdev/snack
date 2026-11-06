<?php

use App\Models\IncomingWebhook;
use App\Models\Message;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);

    $this->webhook = IncomingWebhook::factory()->forChannel($this->channel)->create([
        'name' => 'CI',
    ]);

    $this->token = $this->webhook->token;
});

/**
 * Deliver a payload and hand back the `webhook` block the message carries.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>|null
 */
function deliver(string $token, array $payload): ?array
{
    test()->postJson("/hooks/{$token}", $payload)->assertCreated();

    return Message::query()->latest('id')->firstOrFail()->metadata['webhook'] ?? null;
}

it('keeps the message body as the author typed it', function () {
    $this->postJson("/hooks/{$this->token}", [
        'text' => "Line one\nLine two",
    ])->assertCreated();

    // Through MessageBody like any other message: source text, never HTML.
    expect(Message::query()->firstOrFail()->body)->toBe("Line one\nLine two");
});

it('stores a card built from a Slack attachment', function () {
    $card = deliver($this->token, [
        'text' => 'Build finished',
        'attachments' => [[
            'color' => 'good',
            'title' => 'Build 482',
            'title_link' => 'https://ci.example.com/482',
            'text' => 'All 218 tests passed.',
            'fields' => [
                ['title' => 'Branch', 'value' => 'main', 'short' => true],
                ['title' => 'Duration', 'value' => '4m 12s', 'short' => true],
            ],
            'footer' => 'Jenkins',
        ]],
    ]);

    expect($card['attachments'])->toHaveCount(1);

    $attachment = $card['attachments'][0];

    expect($attachment['color'])->toBe('good')
        ->and($attachment['title'])->toBe('Build 482')
        ->and($attachment['title_link'])->toBe('https://ci.example.com/482')
        ->and($attachment['fields'])->toHaveCount(2)
        ->and($attachment['fields'][0])->toMatchArray(['title' => 'Branch', 'value' => 'main', 'short' => true]);
});

it('drops keys it does not recognise', function () {
    $card = deliver($this->token, [
        'text' => 'hello',
        'attachments' => [[
            'title' => 'Kept',
            // An unauthenticated caller must not be able to choose the keys in a
            // column the frontend reads back and draws.
            'onclick' => 'alert(1)',
            'callback_id' => 'whatever',
        ]],
    ]);

    expect($card['attachments'][0])->toBe(['title' => 'Kept']);
});

it('refuses a javascript: link on a card', function () {
    $card = deliver($this->token, [
        'text' => 'hello',
        'attachments' => [['title' => 'Click me', 'title_link' => 'javascript:alert(1)']],
    ]);

    expect($card['attachments'][0])->not->toHaveKey('title_link');
});

it('keeps a hex colour and discards a bogus one', function () {
    $card = deliver($this->token, [
        'text' => 'hello',
        'attachments' => [
            ['title' => 'a', 'color' => '#36A64F'],
            ['title' => 'b', 'color' => 'chartreuse'],
        ],
    ]);

    expect($card['attachments'][0]['color'])->toBe('#36a64f')
        // Degrades to the default bar rather than failing the delivery: a build
        // pipeline should not go red over a mistyped shade.
        ->and($card['attachments'][1])->not->toHaveKey('color');
});

it('accepts a payload at the attachment and field ceilings', function () {
    $card = deliver($this->token, [
        'text' => 'hello',
        'attachments' => array_fill(0, config('snack.webhooks.max_attachments'), [
            'title' => 'card',
            'fields' => array_fill(0, config('snack.webhooks.max_fields'), [
                'title' => 'f', 'value' => 'v',
            ]),
        ]),
    ]);

    expect($card['attachments'])->toHaveCount(config('snack.webhooks.max_attachments'))
        ->and($card['attachments'][0]['fields'])->toHaveCount(config('snack.webhooks.max_fields'));
});

it('rejects a payload past those ceilings rather than truncating it', function () {
    // Told, not silently trimmed: a caller whose card lost half its rows would
    // debug their own template for an hour before suspecting the server.
    $this->postJson("/hooks/{$this->token}", [
        'text' => 'hello',
        'attachments' => array_fill(0, config('snack.webhooks.max_attachments') + 1, ['title' => 'card']),
    ])->assertStatus(422)->assertJsonValidationErrors('attachments');

    $this->postJson("/hooks/{$this->token}", [
        'text' => 'hello',
        'attachments' => [[
            'title' => 'card',
            'fields' => array_fill(0, config('snack.webhooks.max_fields') + 1, ['title' => 'f', 'value' => 'v']),
        ]],
    ])->assertStatus(422)->assertJsonValidationErrors('attachments.0.fields');
});

it('promotes a card\'s text when the payload has no body', function () {
    $this->postJson("/hooks/{$this->token}", [
        'attachments' => [['title' => 'Deploy failed', 'text' => 'Migration timed out']],
    ])->assertCreated();

    // Slack allows an attachments-only delivery, and a message row with an empty
    // body reads as a glitch in Snack's timeline.
    expect(Message::query()->firstOrFail()->body)->toBe('Deploy failed');
});

it('rejects a delivery whose payload survives as nothing', function () {
    // A card of nothing but a colour is not a card. Told rather than posted as a
    // blank row.
    $this->postJson("/hooks/{$this->token}", ['attachments' => [['color' => 'good']]])
        ->assertStatus(422);

    expect(Message::query()->count())->toBe(0);
});

it('lets a payload override the display name without changing the account', function () {
    $card = deliver($this->token, ['text' => 'deployed', 'username' => 'Deploy']);

    $message = Message::query()->firstOrFail();

    expect($card['username'])->toBe('Deploy')
        // The override is a display string. The *account* is still the hook's
        // bot, so a payload cannot impersonate a colleague by claiming a name.
        ->and($message->sender->name)->toBe('CI')
        ->and($message->sender->is_bot)->toBeTrue();
});

it('falls back to the hook\'s own name', function () {
    $this->webhook->update(['default_username' => 'Continuous Integration']);

    $card = deliver($this->token, ['text' => 'deployed']);

    expect($card['username'])->toBe('Continuous Integration');
});

it('takes an emoji shortcode with or without colons', function () {
    expect(deliver($this->token, ['text' => 'a', 'icon_emoji' => ':rocket:'])['icon'])->toBe('rocket')
        ->and(deliver($this->token, ['text' => 'b', 'icon_emoji' => 'rocket'])['icon'])->toBe('rocket')
        ->and(deliver($this->token, ['text' => 'c', 'icon_emoji' => '<img src=x>'])['icon'])->toBeNull();
});

it('ignores icon_url entirely', function () {
    $card = deliver($this->token, [
        'text' => 'hello',
        // Honouring it would have Snack fetch a remote image on the say-so of an
        // unauthenticated request. See WebhookPayload::icon().
        'icon_url' => 'https://evil.example.com/tracker.png',
    ]);

    expect($card['icon'])->toBeNull()
        ->and($card)->not->toHaveKey('icon_url');
});

it('snapshots the attribution even for a plain text delivery', function () {
    $card = deliver($this->token, ['text' => 'just words']);

    expect($card['username'])->toBe('CI')
        ->and($card['attachments'])->toBe([]);
});

it('keeps a past message\'s attribution when the hook is renamed', function () {
    deliver($this->token, ['text' => 'posted while it was called CI']);

    $this->actingAs($this->owner)->patch(
        "/w/{$this->workspace->slug}/webhooks/{$this->webhook->id}",
        ['name' => 'Renamed'],
    )->assertRedirect();

    $message = Message::query()->firstOrFail();

    // The bot follows the rename — it is one account, and an account that
    // displayed two names depending on which message you looked at would be a
    // worse lie. The snapshot is what keeps the old message honest.
    expect($message->sender->fresh()->name)->toBe('Renamed')
        ->and($message->metadata['webhook']['username'])->toBe('CI');
});

it('exposes the card to the client through the message resource', function () {
    deliver($this->token, [
        'text' => 'Build finished',
        'attachments' => [['title' => 'Build 482', 'color' => 'good']],
    ]);

    $this->actingAs($this->owner)
        ->getJson("/w/{$this->workspace->slug}/channels/{$this->channel->slug}/messages")
        ->assertOk()
        ->assertJsonPath('messages.0.webhook.attachments.0.title', 'Build 482');
});

it('accepts a lone card sent as an object with values wrapped in lists', function () {
    // The shape an automation tool builds from a form: one card as an object,
    // every value as a one-element list.
    $webhook = deliver($this->token, [
        'text' => 'Automation Parent',
        'attachments' => [
            'color' => ['danger'],
            'title' => ['Card declined'],
            'fields' => [
                ['title' => ['Reason'], 'value' => ['Insufficient funds']],
            ],
        ],
    ]);

    expect($webhook['attachments'])->toEqual([[
        'color' => 'danger',
        'title' => 'Card declined',
        'fields' => [
            ['title' => 'Reason', 'value' => 'Insufficient funds', 'short' => false],
        ],
    ]]);
});

it('accepts a lone field sent as an object', function () {
    $webhook = deliver($this->token, [
        'text' => 'x',
        'attachments' => [['title' => 'Card', 'fields' => ['title' => 'Reason', 'value' => 'Late']]],
    ]);

    expect($webhook['attachments'][0]['fields'])->toEqual([
        ['title' => 'Reason', 'value' => 'Late', 'short' => false],
    ]);
});

it('still drops a value that is a list of several', function () {
    $webhook = deliver($this->token, [
        'text' => 'x',
        'attachments' => [['title' => 'Card', 'color' => ['danger', 'good']]],
    ]);

    expect($webhook['attachments'][0])->not->toHaveKey('color');
});
