<?php

use App\Enums\TaskType;
use App\Support\BranchName;

it('composes a prefix, the reference, and the title', function () {
    expect(BranchName::compose('PWA-1499', TaskType::Story, 'Cancel a ride'))
        ->toBe('feature/PWA-1499-cancel-a-ride');
});

it('files a bug and an epic under their own prefixes', function () {
    expect(BranchName::compose('PWA-12', TaskType::Bug, 'Login loops'))
        ->toBe('bugfix/PWA-12-login-loops')
        ->and(BranchName::compose('PWA-13', TaskType::Epic, 'Payments'))
        ->toBe('epic/PWA-13-payments');

    // A story and a task differ in planning, not in the repository.
    expect(BranchName::compose('PWA-14', TaskType::Task, 'Rotate keys'))
        ->toBe('feature/PWA-14-rotate-keys');
});

it('strips everything git refuses in a ref name', function () {
    $branch = BranchName::compose(
        'PWA-20',
        TaskType::Bug,
        'Fix ~this~ ^bad^ [name]: don\'t..break?',
    );

    expect($branch)->toBe('bugfix/PWA-20-fix-this-bad-name-dontbreak')
        ->and($branch)->not->toMatch('/[\s~^:?*\[\\\\]|\.\./');
});

it('cuts a long title at a word boundary', function () {
    $branch = BranchName::compose(
        'PWA-30',
        TaskType::Story,
        'Riders should be able to cancel a ride after the driver has accepted it',
    );

    // Long enough to say what the work is, and never ending mid-word — a name
    // cut to "acce" reads as a typo and invites somebody to fix it.
    expect($branch)->toBe('feature/PWA-30-riders-should-be-able-to-cancel-a-ride-after-the')
        ->and(str_ends_with($branch, '-'))->toBeFalse();
});

it('keeps the reference when the title slugs away to nothing', function () {
    // The reference is the load-bearing half: a branch without it cannot be
    // traced back to anything, so an untransliterable title loses the title.
    expect(BranchName::compose('PWA-40', TaskType::Story, '🎉🎉🎉'))
        ->toBe('feature/PWA-40');
});
