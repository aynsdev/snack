<?php

use App\Enums\StandupResponseState;
use App\Enums\WorkspaceRole;
use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Standup;
use App\Models\StandupResponse;
use App\Models\StandupRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

/**
 * Answering by typing into the DM.
 *
 * The prompt says "Answer here and I will post it to #channel", so this covers
 * the path a person takes when they believe it: a plain message in the bot's
 * conversation reaches the channel, and one that is not an answer is left alone.
 */
beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->channel = makeChannel($this->workspace);

    $this->ada = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    addToChannel($this->channel, $this->ada);

    /*
     * A second person on the roster, who never answers. Without them, Ada's
     * reply is the last one outstanding and the run closes on the spot — every
     * test here would then be asserting against the summary as well as against
     * the thing it means to test.
     */
    $this->bo = joinWorkspace($this->workspace, null, WorkspaceRole::Member);
    addToChannel($this->channel, $this->bo);

    $this->standup = Standup::factory()
        ->forChannel($this->channel)
        ->daily()
        ->at('09:30')
        ->create(['questions' => ['What are you working on?', 'Anything blocking you?']]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:30:00', 'UTC'));

    try {
        Artisan::call('snack:run-standups');
    } finally {
        CarbonImmutable::setTestNow();
    }

    $this->run = StandupRun::query()->firstOrFail();

    $this->response = StandupResponse::query()
        ->where('standup_run_id', $this->run->id)
        ->where('user_id', $this->ada->id)
        ->firstOrFail();

    $this->conversation = Conversation::query()->findOrFail($this->response->conversation_id);
});

/**
 * Say something in the standup DM, as the person who was asked.
 */
function replyToStandup(string $body, ?Message $parent = null): void
{
    test()->actingAs(test()->ada)
        ->postJson(
            route('conversations.messages.store', [
                'workspace' => test()->workspace->slug,
                'conversation' => test()->conversation,
            ]),
            ['body' => $body, 'parent_message_id' => $parent?->id],
        )
        ->assertCreated();
}

/**
 * The bot's messages in this DM, oldest first — the prompt, then anything it
 * said back.
 */
function botMessages(): Collection
{
    return Message::query()
        ->where('conversation_id', test()->conversation->id)
        ->whereRelation('sender', 'is_bot', true)
        ->orderBy('id')
        ->get();
}

it('reads a numbered reply as the answers and posts them to the channel', function () {
    replyToStandup("1. Shipping the parser\n2. Review on #418");

    $this->response->refresh();

    expect($this->response->state)->toBe(StandupResponseState::Submitted)
        ->and($this->response->answerPairs())->toBe([
            ['question' => 'What are you working on?', 'answer' => 'Shipping the parser'],
            ['question' => 'Anything blocking you?', 'answer' => 'Review on #418'],
        ]);

    $posted = Message::query()->findOrFail($this->response->answer_message_id);

    expect($posted->channel_id)->toBe($this->channel->id)
        ->and($posted->body)->toContain('Shipping the parser')
        ->and($posted->body)->toContain('Review on #418');
});

it('keeps an unsplittable reply whole rather than refusing it', function () {
    replyToStandup('Still on the importer, nothing in my way.');

    expect($this->response->fresh()->answerPairs())->toBe([
        ['question' => 'What are you working on?', 'answer' => 'Still on the importer, nothing in my way.'],
    ]);
});

it('takes a whole-message skip phrase as nothing to report', function () {
    replyToStandup('nothing to report');

    $this->response->refresh();

    expect($this->response->state)->toBe(StandupResponseState::Skipped)
        ->and($this->response->answerPairs())->toBe([])
        // A skip is counted in the summary, never posted on its own.
        ->and($this->response->answer_message_id)->toBeNull()
        ->and(Message::query()->where('channel_id', $this->channel->id)->count())->toBe(0);
});

it('says where the update went', function () {
    replyToStandup('Shipping the parser');

    expect(botMessages()->last()->body)->toBe("Thanks — posted to #{$this->channel->slug}.");
});

it('does not promise the channel a skip it will not post', function () {
    replyToStandup('skip');

    expect(botMessages()->last()->body)->toBe('Noted — nothing to report today.');
});

/**
 * The card is retired server-side by a reply, and the person who typed it holds
 * no handle on the response — so the retirement has to be announced, or the form
 * goes on offering to answer a standup that is already in the channel.
 */
it('announces the retired card so the form stops offering to answer', function () {
    replyToStandup('Shipping the parser');

    $prompt = Message::query()->findOrFail($this->response->prompt_message_id);

    expect($prompt->fresh()->metadata['standup']['state'])->toBe('submitted');

    Event::assertDispatched(
        MessageUpdated::class,
        fn (MessageUpdated $event): bool => $event->message->id === $prompt->id,
    );
});

it('answers once, and leaves everything said afterwards alone', function () {
    replyToStandup('Shipping the parser');
    replyToStandup('Also, coffee is out');

    expect(Message::query()->where('channel_id', $this->channel->id)->count())->toBe(1)
        ->and(Message::query()->where('channel_id', $this->channel->id)->first()->body)
        ->not->toContain('coffee');
});

it('takes a reply in the prompt thread as an answer', function () {
    $prompt = Message::query()->findOrFail($this->response->prompt_message_id);

    replyToStandup('Shipping the parser', $prompt);

    expect($this->response->fresh()->state)->toBe(StandupResponseState::Submitted);
});

it('leaves a thread on an unrelated message alone', function () {
    $chatter = makeConversationMessage($this->conversation, $this->ada, 'Morning');

    replyToStandup('Shipping the parser', $chatter);

    expect($this->response->fresh()->state)->toBe(StandupResponseState::Pending);
});

it('ignores a message in a conversation with no standup outstanding', function () {
    $elsewhere = makeConversation($this->workspace, [$this->ada, $this->bo]);

    test()->actingAs($this->ada)
        ->postJson(
            route('conversations.messages.store', [
                'workspace' => $this->workspace->slug,
                'conversation' => $elsewhere,
            ]),
            ['body' => "1. Shipping the parser\n2. Nothing"],
        )
        ->assertCreated();

    expect($this->response->fresh()->state)->toBe(StandupResponseState::Pending);
});

it('does not answer a standup whose run has closed', function () {
    // Four hours on: the close window expires and the run is written up.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 13:31:00', 'UTC'));

    try {
        Artisan::call('snack:run-standups');
    } finally {
        CarbonImmutable::setTestNow();
    }

    replyToStandup('Shipping the parser');

    expect($this->response->fresh()->state)->toBe(StandupResponseState::Pending)
        ->and(Message::query()->where('channel_id', $this->channel->id)->count())->toBe(0);
});
