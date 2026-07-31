<?php

use App\Enums\MentionType;
use App\Services\MentionParser;

/**
 * The mention grammar.
 *
 * Pure text in, tokens out — no workspace, no database. Whether any of these
 * handles belong to a real person who may be notified is SyncMentions' problem.
 */
it('finds a handle', function () {
    expect(MentionParser::extract('Morning @ada, ready?')['usernames'])->toBe(['ada']);
});

it('finds several handles and de-duplicates them', function () {
    $parsed = MentionParser::extract('@ada @bo and @ada again');

    expect($parsed['usernames'])->toBe(['ada', 'bo']);
});

it('lowercases handles so casing does not split a mention', function () {
    // `username` is citext, so "@Ada" and "@ada" are the same person.
    expect(MentionParser::extract('@Ada and @ADA')['usernames'])->toBe(['ada']);
});

it('finds broadcast mentions', function () {
    $parsed = MentionParser::extract('@everyone please read, @here now');

    expect($parsed['broadcasts'])->toBe([MentionType::Everyone, MentionType::Here])
        // A broadcast is not somebody's handle and must not be looked up as one.
        ->and($parsed['usernames'])->toBe([]);
});

it('finds channel links', function () {
    expect(MentionParser::extract('see #design and #general')['channels'])
        ->toBe(['design', 'general']);
});

it('ignores an email address', function () {
    // The character before "@" rules this out, which is the whole point of the
    // lookbehind — otherwise every address in a message mentions somebody.
    expect(MentionParser::extract('write to ada@snack.test')['usernames'])->toBe([]);
});

it('ignores a doubled sigil', function () {
    expect(MentionParser::extract('@@ada')['usernames'])->toBe([]);
});

it('does not treat trailing punctuation as part of a handle', function () {
    expect(MentionParser::extract('ask @ada.')['usernames'])->toBe(['ada'])
        ->and(MentionParser::extract('@bo, @cy!')['usernames'])->toBe(['bo', 'cy']);
});

it('keeps dots and hyphens inside a handle', function () {
    expect(MentionParser::extract('@ada.lovelace and @jean-luc')['usernames'])
        ->toBe(['ada.lovelace', 'jean-luc']);
});

// -- Code is not a mention ---------------------------------------------------

it('ignores a handle inside inline code', function () {
    /*
     * Has to match the client's tokenizer, where code consumes its contents
     * whole. If they disagreed, a message would notify someone it did not
     * highlight — or highlight someone it did not notify.
     */
    expect(MentionParser::extract('use `@ada` as the flag')['usernames'])->toBe([]);
});

it('ignores a handle inside a fenced block', function () {
    $body = <<<'TEXT'
    Try this:

    ```php
    $user = '@ada';
    ```
    TEXT;

    expect(MentionParser::extract($body)['usernames'])->toBe([]);
});

it('ignores a handle in an unterminated fence', function () {
    // The renderer runs an unterminated fence to the end of the message, so the
    // parser has to agree rather than falling back to plain text.
    expect(MentionParser::extract("```\n@ada\n")['usernames'])->toBe([]);
});

it('still finds handles outside the code', function () {
    expect(MentionParser::extract('@bo, try `@ada` here')['usernames'])->toBe(['bo']);
});

it('does not splice text across removed code into a handle', function () {
    // Deleting the code outright would leave "@ada" where the author wrote
    // something else entirely.
    expect(MentionParser::extract('`x`@ada')['usernames'])->toBe(['ada'])
        ->and(MentionParser::extract('@`x`ada')['usernames'])->toBe([]);
});

it('finds nothing in a message with no mentions', function () {
    $parsed = MentionParser::extract('Just a normal message.');

    expect($parsed['usernames'])->toBe([])
        ->and($parsed['broadcasts'])->toBe([])
        ->and($parsed['channels'])->toBe([]);
});
