<?php

use App\Support\GitHubRepository;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->project = makeProject($this->workspace, $this->owner, ['name' => 'Demo', 'key' => 'DEMO']);
    $this->actingAs($this->owner);
});

function saveRepository(object $test, ?string $url): TestResponse
{
    return $test->patch(route('projects.update', [$test->workspace->slug, $test->project->slug]), ['repository_url' => $url]);
}

it('stores a GitHub link in one spelling', function (string $input) {
    saveRepository($this, $input)->assertSessionHasNoErrors();

    expect($this->project->fresh()->repository_url)->toBe('https://github.com/acme/demo-app');
})->with([
    'https' => 'https://github.com/acme/demo-app',
    'https with .git' => 'https://github.com/acme/demo-app.git',
    'ssh' => 'git@github.com:acme/demo-app.git',
]);

it('refuses anything but a github.com repository', function (string $input) {
    saveRepository($this, $input)->assertSessionHasErrors('repository_url');

    expect($this->project->fresh()->repository_url)->toBeNull();
})->with([
    'another host' => 'https://gitlab.com/acme/demo',
    'plain http' => 'http://github.com/acme/demo',
    'userinfo' => 'https://token@github.com/acme/demo',
    'a deeper path' => 'https://github.com/acme/demo/tree/main',
    'ssh without .git' => 'git@github.com:acme/demo',
    'dot segments' => 'https://github.com/../demo',
    'not a url' => 'demo',
]);

it('clears the link', function () {
    $this->project->forceFill(['repository_url' => 'https://github.com/acme/demo'])->save();

    saveRepository($this, '')->assertSessionHasNoErrors();

    expect($this->project->fresh()->repository_url)->toBeNull();
});

it('is only changed by somebody who can manage the project', function () {
    $member = joinWorkspace($this->workspace);
    addToProject($this->project, $member);

    $this->actingAs($member);
    saveRepository($this, 'https://github.com/acme/demo')->assertForbidden();
});

it('normalises without a request too', function () {
    expect(GitHubRepository::normalize('git@github.com:Acme/Repo.js.git'))->toBe('https://github.com/Acme/Repo.js')
        ->and(GitHubRepository::normalize('https://github.com/acme/..'))->toBeNull();
});

describe('base branch', function () {
    beforeEach(function () {
        $this->project->forceFill(['repository_url' => 'https://github.com/acme/demo'])->save();
    });

    it('stores a safe branch name', function (string $branch) {
        $this->patch(route('projects.update', [$this->workspace->slug, $this->project->slug]), ['repository_base_branch' => $branch])
            ->assertSessionHasNoErrors();

        expect($this->project->fresh()->repository_base_branch)->toBe($branch);
    })->with(['main', 'release/2.x', 'feature/LA-12_fix.v2']);

    it('refuses a name git or a shell would read differently', function (string $branch) {
        $this->patch(route('projects.update', [$this->workspace->slug, $this->project->slug]), ['repository_base_branch' => $branch])
            ->assertSessionHasErrors('repository_base_branch');

        expect($this->project->fresh()->repository_base_branch)->toBeNull();
    })->with([
        'dot dot' => 'a..b',
        'leading dash' => '-rf',
        'leading slash' => '/main',
        'trailing slash' => 'main/',
        'lock suffix' => 'main.lock',
        'reflog syntax' => 'main@{1}',
        'space' => 'my branch',
        'shell' => 'main;rm',
        'too long' => str_repeat('a', 201),
        'empty segment' => 'a//b',
        'segment starting with a dot' => 'a/.x',
        'leading dot' => '.hidden',
        'segment ending in .lock' => 'a/b.lock/c',
        'trailing dot' => 'main.',
        'control character' => "ma\tin",
        'tilde' => 'main~1',
        'caret' => 'main^',
        'colon' => 'a:b',
        'question mark' => 'a?b',
        'star' => 'a*b',
        'bracket' => 'a[b',
        'backslash' => 'a\\b',
    ]);

    it('needs a repository first', function () {
        $this->project->forceFill(['repository_url' => null])->save();

        $this->patch(route('projects.update', [$this->workspace->slug, $this->project->slug]), ['repository_base_branch' => 'main'])
            ->assertSessionHasErrors('repository_base_branch');
    });

    it('is cleared with the repository', function () {
        $this->project->forceFill(['repository_base_branch' => 'main'])->save();

        saveRepository($this, '')->assertSessionHasNoErrors();

        expect($this->project->fresh()->repository_base_branch)->toBeNull();
    });
});
