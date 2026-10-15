<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\SaveRelease;
use App\Mcp\Tools\Concerns\PlacesTasks;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Edit a release, or mark it as gone out — the Releases page's Edit and
 * Release buttons, behind the same project-lead right.
 */
#[Name('update_release')]
#[Title('Update Release')]
#[Description(<<<'TEXT'
Change a release: rename it, change its notes or dates, or mark it released.
Only the fields you pass are changed. Only project leads can.

- `released`: true marks it as gone out (today, or on `released_on`); false
  marks it as not released again. Unfinished tasks stay in it either way.
- `start_date` / `target_date`: a date, or "none" to clear it.

Only change what the person asked you to change.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class UpdateReleaseTool extends SnackTool
{
    use PlacesTasks;
    use ResolvesProjectWork;

    public function handle(Request $request, SaveRelease $saveRelease): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $request->validate([
            'project' => ['required', 'string'],
            'release' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'min:1', 'max:40'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'start_date' => ['sometimes', 'string'],
            'target_date' => ['sometimes', 'string'],
            'released' => ['sometimes', 'boolean'],
            'released_on' => ['sometimes', 'date'],
        ]);

        $project = $this->project($context, $request->get('project'));

        if ($project === null) {
            return Response::error($this->noSuchProject($context, $request->get('project')));
        }

        $release = $this->releaseNamed($project, $request->get('release'));

        if (is_string($release) || $release === null) {
            return Response::error($release ?? $this->releaseOptions($project));
        }

        $allowed = Gate::forUser($context->user)->inspect('manageSprints', $project);

        if ($allowed->denied()) {
            return Response::error("This person cannot change releases on {$project->key}: {$allowed->message()}");
        }

        $arguments = $request->all();
        $attributes = [];
        $changed = [];

        if (array_key_exists('name', $arguments)) {
            $name = trim((string) $arguments['name']);

            if ($project->releases()->where('name', $name)->whereKeyNot($release->id)->exists()) {
                return Response::error("{$project->key} already has a release called [{$name}]. Nothing was changed.");
            }

            $attributes['name'] = $name;
            $changed[] = "name → {$name}";
        }

        if (array_key_exists('description', $arguments)) {
            $attributes['notes'] = $arguments['description'];
            $changed[] = 'notes';
        }

        foreach (['start_date' => 'starts_on', 'target_date' => 'ends_on'] as $argument => $column) {
            if (! array_key_exists($argument, $arguments)) {
                continue;
            }

            $date = $this->dueDate($arguments[$argument]);

            if ($date === false) {
                return Response::error("[{$arguments[$argument]}] is not a date. Use a date like 2026-10-31, or \"none\". Nothing was changed.");
            }

            $attributes[$column] = $date;
            $changed[] = str_replace('_', ' ', $argument);
        }

        $startsOn = $attributes['starts_on'] ?? $release->starts_on?->toDateString();
        $endsOn = $attributes['ends_on'] ?? $release->ends_on?->toDateString();

        if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) {
            return Response::error('A release cannot go out before it starts. Nothing was changed.');
        }

        if ($attributes === [] && ! array_key_exists('released', $arguments)) {
            return Response::error('Nothing to change was given.');
        }

        if ($attributes !== []) {
            $release = $saveRelease->update($release, $attributes);
        }

        if (array_key_exists('released', $arguments)) {
            $released = filter_var($arguments['released'], FILTER_VALIDATE_BOOLEAN);
            $at = $released && isset($arguments['released_on']) ? Carbon::parse($arguments['released_on']) : null;

            $release = $saveRelease->markReleased($release, $released, $at);
            $changed[] = $released ? 'released '.$release->released_at->toFormattedDateString() : 'not released';
        }

        return Response::text("Updated release {$release->name} on {$project->key}: ".implode(', ', $changed).'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('The project key (ENG) or name.')->required(),
            'release' => $schema->string()->description('The current release name, such as v3.2.1.')->required(),
            'name' => $schema->string()->description('A new name.'),
            'description' => $schema->string()->description('New notes. Replaces the old ones entirely.'),
            'start_date' => $schema->string()->description('A date such as 2026-10-01, or "none".'),
            'target_date' => $schema->string()->description('A date such as 2026-10-31, or "none".'),
            'released' => $schema->boolean()->description('true to mark it released, false to undo that.'),
            'released_on' => $schema->string()->description('With released: true, the date it went out. Defaults to today.'),
        ];
    }
}
