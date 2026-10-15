<?php

namespace App\Mcp\Tools;

use App\Actions\Projects\SaveRelease;
use App\Mcp\Tools\Concerns\PlacesTasks;
use App\Mcp\Tools\Concerns\ResolvesProjectWork;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Plan a release, through the action the Releases page uses and behind the
 * same right: a project lead's, like planning a sprint.
 */
#[Name('create_release')]
#[Title('Create Release')]
#[Description(<<<'TEXT'
Plan a new release (a version, such as v3.2.1) on a project. Only project
leads can. Names are unique per project. Put tasks in it afterwards with
`create_task`, `update_task`, or `update_tasks` and `release`. Only create one
when the person asked you to.
TEXT)]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class CreateReleaseTool extends SnackTool
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
            'name' => ['required', 'string', 'min:1', 'max:40'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'target_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $project = $this->project($context, $request->get('project'));

        if ($project === null) {
            return Response::error($this->noSuchProject($context, $request->get('project')));
        }

        $allowed = Gate::forUser($context->user)->inspect('manageSprints', $project);

        if ($allowed->denied()) {
            return Response::error("This person cannot plan releases on {$project->key}: {$allowed->message()}");
        }

        $name = trim((string) $request->get('name'));

        if ($project->releases()->where('name', $name)->exists()) {
            return Response::error("{$project->key} already has a release called [{$name}]. ".$this->releaseOptions($project));
        }

        $startsOn = $request->get('start_date');
        $endsOn = $request->get('target_date');

        if ($startsOn !== null && $endsOn !== null && strtotime($endsOn) < strtotime($startsOn)) {
            return Response::error('A release cannot go out before it starts. Nothing was created.');
        }

        $release = $saveRelease->create($project, [
            'name' => $name,
            'notes' => $request->get('description'),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);

        return Response::text("Created release {$release->name} on {$project->key}"
            .($release->ends_on !== null ? ', targeting '.$release->ends_on->toFormattedDateString() : '').'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('The project key (ENG) or name.')->required(),
            'name' => $schema->string()->description('The version name, such as v3.2.1.')->required(),
            'description' => $schema->string()->description('Release notes or what this version is for.'),
            'start_date' => $schema->string()->description('A date such as 2026-10-01.'),
            'target_date' => $schema->string()->description('When it is meant to go out, such as 2026-10-31.'),
        ];
    }
}
