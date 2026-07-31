<?php

use App\Support\SearchQuery;

it('leaves a plain query as terms', function () {
    $query = SearchQuery::parse('quarterly budget review');

    expect($query->terms)->toBe('quarterly budget review')
        ->and($query->hasFilters())->toBeFalse()
        ->and($query->isEmpty())->toBeFalse();
});

it('lifts filters out of the terms', function () {
    $query = SearchQuery::parse('budget from:@ada in:#finance has:file');

    expect($query->terms)->toBe('budget')
        ->and($query->from)->toBe(['ada'])
        ->and($query->in)->toBe(['finance'])
        ->and($query->requiresFile)->toBeTrue();
});

it('reads dates on both sides', function () {
    $query = SearchQuery::parse('after:2026-01-01 before:2026-06-30 launch');

    expect($query->terms)->toBe('launch')
        ->and($query->after?->toDateString())->toBe('2026-01-01')
        ->and($query->before?->toDateString())->toBe('2026-06-30');
});

it('drops a date it cannot read rather than failing', function () {
    $query = SearchQuery::parse('after:soon meeting');

    expect($query->after)->toBeNull()
        ->and($query->terms)->toBe('meeting');
});

it('accepts several of the same filter', function () {
    $query = SearchQuery::parse('from:@ada from:@bo');

    expect($query->from)->toBe(['ada', 'bo']);
});

it('does not repeat a filter named twice', function () {
    expect(SearchQuery::parse('in:#design in:#design')->in)->toBe(['design']);
});

it('ignores a has: value nobody implemented', function () {
    $query = SearchQuery::parse('has:unicorn notes');

    expect($query->requiresFile)->toBeFalse()
        ->and($query->terms)->toBe('notes');
});

it('leaves unrecognized keys in the text where the author put them', function () {
    $query = SearchQuery::parse('re: the thing we discussed');

    expect($query->terms)->toBe('re: the thing we discussed')
        ->and($query->hasFilters())->toBeFalse();
});

it('does not mistake a url for a filter', function () {
    $query = SearchQuery::parse('see https://snack.test/docs');

    expect($query->terms)->toBe('see https://snack.test/docs');
});

it('accepts a quoted filter value', function () {
    expect(SearchQuery::parse('in:"design"')->in)->toBe(['design']);
});

it('treats filters alone as a real search', function () {
    $query = SearchQuery::parse('in:#design has:file');

    expect($query->terms)->toBe('')
        ->and($query->hasTerms())->toBeFalse()
        ->and($query->hasFilters())->toBeTrue()
        ->and($query->isEmpty())->toBeFalse();
});

it('treats an empty box as nothing to search for', function () {
    expect(SearchQuery::parse('')->isEmpty())->toBeTrue();
});

it('treats a one-character query as too short to run', function () {
    expect(SearchQuery::parse('a')->isEmpty())->toBeTrue();
});

it('bounds how long a query can be', function () {
    expect(mb_strlen(SearchQuery::parse(str_repeat('a', 500))->raw))->toBe(300);
});
