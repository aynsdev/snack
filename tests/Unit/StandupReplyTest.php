<?php

use App\Support\StandupReply;

/**
 * Reading a typed reply as an answer.
 *
 * The parser is a ladder of guesses, so what is worth protecting is the order
 * they are tried in and — just as much — where the guessing stops. A reply that
 * lands whole against the first question is recoverable by reading it; one that
 * has been confidently mis-split is not.
 *
 * @return array<int, string>
 */
function standupQuestions(): array
{
    return ['What are you working on?', 'Anything blocking you?'];
}

it('takes a whole-message skip phrase as a skip', function (string $body) {
    $reply = StandupReply::parse($body, standupQuestions());

    expect($reply->skipped)->toBeTrue()
        ->and($reply->answers)->toBe([]);
})->with([
    'nothing to report',
    'Nothing to report.',
    'NOTHING TO REPORT!',
    '*nothing to report*',
    'skip',
    'n/a',
]);

it('does not read the word nothing inside a sentence as a skip', function () {
    $reply = StandupReply::parse('Nothing blocking me, shipping the parser', standupQuestions());

    expect($reply->skipped)->toBeFalse()
        ->and($reply->answers)->toBe(['Nothing blocking me, shipping the parser']);
});

it('splits numbered answers onto the questions they number', function () {
    $reply = StandupReply::parse("1. The parser\n2) Waiting on review", standupQuestions());

    expect($reply->answers)->toBe(['The parser', 'Waiting on review'])
        ->and($reply->skipped)->toBeFalse();
});

it('keeps the lines under a number with it', function () {
    $reply = StandupReply::parse("1. The parser\nand the tests\n2. Nope", standupQuestions());

    expect($reply->answers)->toBe(["The parser\nand the tests", 'Nope']);
});

it('leaves a numbered question unanswered rather than shifting the rest up', function () {
    $reply = StandupReply::parse('2. Waiting on review', standupQuestions());

    expect($reply->answers)->toBe(['', 'Waiting on review']);
});

it('drops the preamble before the first number', function () {
    $reply = StandupReply::parse("here you go:\n1. The parser\n2. Nope", standupQuestions());

    expect($reply->answers)->toBe(['The parser', 'Nope']);
});

/**
 * The guard on rule 2. Markers that cannot be a set of answers are not treated
 * as markers at all — the reply falls down the ladder like any other shape,
 * rather than being renumbered onto whichever questions happen to be left.
 */
it('does not renumber markers that cannot be answer markers', function (string $body) {
    $questions = ['One?', 'Two?', 'Three?'];

    expect(StandupReply::parse($body, $questions)->answers)->toBe([$body]);
})->with([
    'a number outside the question range' => "1. Shipped\n5. Nope",
    'the same number twice' => "1. Shipped\n1. Again",
]);

it('splits on the questions when they are pasted back as headings', function () {
    $body = <<<'TEXT'
    What are you working on?
    The parser
    Anything blocking you?
    Review on #418
    TEXT;

    expect(StandupReply::parse($body, standupQuestions())->answers)
        ->toBe(['The parser', 'Review on #418']);
});

it('matches a heading through emphasis and punctuation', function () {
    $body = "**What are you working on**\nThe parser\n_Anything blocking you:_\nNope";

    expect(StandupReply::parse($body, standupQuestions())->answers)
        ->toBe(['The parser', 'Nope']);
});

it('splits blank-line blocks positionally when there is one per question', function () {
    $reply = StandupReply::parse("The parser,\nstill\n\nReview on #418", standupQuestions());

    expect($reply->answers)->toBe(["The parser,\nstill", 'Review on #418']);
});

it('splits one line per question when the counts match', function () {
    $reply = StandupReply::parse("The parser\nReview on #418", standupQuestions());

    expect($reply->answers)->toBe(['The parser', 'Review on #418']);
});

/**
 * Where the guessing stops. Three paragraphs against two questions could be
 * split half a dozen ways, and every one of them files something under a
 * question the person did not answer.
 */
it('keeps a reply whole when nothing lines up', function () {
    $body = "The parser\n\nAnd the tests\n\nNothing blocking";

    expect(StandupReply::parse($body, standupQuestions())->answers)->toBe([$body]);
});

it('never splits a single-question standup', function () {
    $body = "Line one\nLine two";

    expect(StandupReply::parse($body, ['How did it go?'])->answers)->toBe([$body]);
});

it('reports an empty body as nothing to submit', function () {
    $reply = StandupReply::parse("  \n \n", standupQuestions());

    expect($reply->isEmpty())->toBeTrue()
        ->and($reply->skipped)->toBeFalse();
});

it('does not report a skip as empty', function () {
    expect(StandupReply::parse('nothing to report', standupQuestions())->isEmpty())->toBeFalse();
});
