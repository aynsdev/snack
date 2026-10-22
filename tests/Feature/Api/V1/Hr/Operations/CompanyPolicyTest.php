<?php

use App\Enums\WorkspaceRole;
use App\Models\CompanyPolicy;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 09:00:00');
    Storage::fake('local');

    [$this->workspace, $this->owner] = makeWorkspaceWithOwner();
    $this->admin = joinWorkspace($this->workspace, null, WorkspaceRole::Admin);
    $this->member = joinWorkspace($this->workspace, null, WorkspaceRole::Member);

    $this->base = "/api/v1/w/{$this->workspace->slug}/hr/policies";
    $this->as = function (User $user) {
        app('auth')->forgetGuards();

        return $this->withToken(mobileToken($user));
    };
});

it('publishes a policy with a file, which everybody reads and acknowledges', function () {
    $id = ($this->as)($this->admin)
        ->post($this->base, [
            'title' => 'Code of conduct',
            'body' => 'Be kind.',
            'requires_acknowledgement' => true,
            'file' => UploadedFile::fake()->create('conduct.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Code of conduct')
        ->assertJsonPath('data.has_file', true)
        ->assertJsonPath('data.acknowledged_count', 0)
        ->json('data.id');

    ($this->as)($this->member)
        ->postJson("{$this->base}/{$id}/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.acknowledged', true);
    ($this->as)($this->member)->postJson("{$this->base}/{$id}/acknowledge")->assertOk();

    ($this->as)($this->member)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data.policies.0.acknowledged', true)
        ->assertJsonPath('data.policies.0.acknowledged_count', null)
        ->assertJsonPath('data.can_manage', false);

    ($this->as)($this->admin)
        ->getJson($this->base)
        ->assertJsonPath('data.policies.0.acknowledged_count', 1)
        ->assertJsonPath('data.headcount', 3);

    ($this->as)($this->member)->get("{$this->base}/{$id}/file")->assertOk()->assertDownload('conduct.pdf');
});

it('asks everybody again when the text changes, and deletes a policy', function () {
    $policy = $this->workspace->companyPolicies()->create([
        'title' => 'Code of conduct', 'body' => 'Be kind.', 'requires_acknowledgement' => true, 'author_id' => $this->admin->id,
    ]);
    $policy->acknowledgements()->create(['employee_id' => $this->member->employeeIn($this->workspace)->id, 'acknowledged_at' => now()]);

    ($this->as)($this->admin)
        ->postJson("{$this->base}/{$policy->id}", ['title' => 'Code of Conduct', 'body' => 'Be kind.', 'requires_acknowledgement' => true])
        ->assertOk()
        ->assertJsonPath('data.title', 'Code of Conduct')
        ->assertJsonPath('data.acknowledged_count', 1);

    ($this->as)($this->admin)
        ->postJson("{$this->base}/{$policy->id}", ['title' => 'Code of Conduct', 'body' => 'Be kind, and on time.', 'requires_acknowledgement' => true])
        ->assertOk()
        ->assertJsonPath('data.acknowledged_count', 0);

    ($this->as)($this->admin)->deleteJson("{$this->base}/{$policy->id}")->assertNoContent();

    expect(CompanyPolicy::query()->count())->toBe(0);
});

it('keeps members from writing policies, and outsiders out', function () {
    $policy = $this->workspace->companyPolicies()->create([
        'title' => 'Leave', 'body' => 'x', 'requires_acknowledgement' => false, 'author_id' => $this->admin->id,
    ]);

    ($this->as)($this->member)->postJson($this->base, ['title' => 'Mine', 'body' => 'x'])->assertForbidden();
    ($this->as)($this->member)->deleteJson("{$this->base}/{$policy->id}")->assertForbidden();
    ($this->as)($this->member)->postJson("{$this->base}/{$policy->id}/acknowledge")->assertForbidden();
    ($this->as)(User::factory()->create())->getJson($this->base)->assertNotFound();
});
