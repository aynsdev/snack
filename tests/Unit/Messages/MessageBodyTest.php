<?php

use App\Services\MessageBody;

/**
 * Normalization of composer input.
 *
 * The rule this whole class exists to hold: the author's text is preserved, and
 * only characters that would corrupt storage or misrepresent the message are
 * removed. Nothing is escaped, because nothing downstream parses it as markup.
 */
it('keeps the author text intact', function () {
    $body = MessageBody::fromInput('Ship it **today** if the tests pass.');

    expect($body->source)->toBe('Ship it **today** if the tests pass.');
});

it('trims surrounding whitespace', function () {
    expect(MessageBody::fromInput("  \n Hello \n  ")->source)->toBe('Hello');
});

it('treats a whitespace-only message as empty', function () {
    expect(MessageBody::fromInput("   \n\t  ")->isEmpty())->toBeTrue();
});

it('normalizes windows line endings', function () {
    // Otherwise the same message typed on two platforms produces two different
    // rows, and every byte comparison — including the "unchanged edit" check —
    // silently stops working.
    expect(MessageBody::fromInput("one\r\ntwo\rthree")->source)->toBe("one\ntwo\nthree");
});

it('collapses runs of blank lines', function () {
    expect(MessageBody::fromInput("top\n\n\n\n\nbottom")->source)->toBe("top\n\nbottom");
});

it('preserves a deliberate single blank line', function () {
    expect(MessageBody::fromInput("first para\n\nsecond para")->source)
        ->toBe("first para\n\nsecond para");
});

it('leaves html-looking text exactly as typed', function () {
    $hostile = '<img src=x onerror="alert(1)">';

    // Storing source rather than markup is what makes this safe; escaping here
    // would only corrupt a message about HTML and double-escape at render time.
    expect(MessageBody::fromInput($hostile)->source)->toBe($hostile);
});

it('strips bidirectional override characters', function () {
    expect(MessageBody::fromInput("invoice\u{202E}fdp.exe")->source)->toBe('invoicefdp.exe');
});

it('strips zero-width characters', function () {
    expect(MessageBody::fromInput("ad\u{200B}min")->source)->toBe('admin');
});

it('strips control characters but keeps tabs and newlines', function () {
    expect(MessageBody::fromInput("a\x00b\x07c\td\ne")->source)->toBe("abc\td\ne");
});

it('flattens formatting markers for the plaintext form', function () {
    $body = MessageBody::fromInput('Try **this** and _that_ and ~~not this~~ and `code`.');

    expect($body->plaintext)->toBe('Try this and that and not this and code.');
});

it('keeps the contents of a code block in the plaintext form', function () {
    $body = MessageBody::fromInput("Here:\n```php\n\$user->save();\n```");

    // The fences go, the code stays: a snippet should still be findable by
    // search and readable in a notification preview.
    expect($body->plaintext)->toBe('Here: $user->save();');
});

it('collapses newlines in the plaintext form', function () {
    // Previews and search snippets are single-line contexts.
    expect(MessageBody::fromInput("one\n\ntwo")->plaintext)->toBe('one two');
});

it('produces both persisted columns', function () {
    expect(MessageBody::fromInput('**Hi**')->toAttributes())
        ->toBe(['body' => '**Hi**', 'body_plaintext' => 'Hi']);
});
