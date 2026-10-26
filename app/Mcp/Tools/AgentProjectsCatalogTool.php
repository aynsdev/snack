<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ServesAgents;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Every project the agent's owner can see, with its repository — how the
 * Kusina daemon finds code in a project other than the one a task is in.
 */
#[Name('agent_projects_catalog')]
#[Title('Agent Projects Catalog')]
#[Description(<<<'TEXT'
For agent connectors only: every project this person can see, as
{"projects": [{"id", "key", "name", "description", "repository_url", "repository_base_branch", "archived", "agents_enabled"}]}.
TEXT)]
#[IsReadOnly]
class AgentProjectsCatalogTool extends SnackTool
{
    use ServesAgents;

    public function handle(Request $request): Response
    {
        $context = $this->agentContext($request, 'agent_projects_catalog');

        if ($context instanceof Response) {
            return $context;
        }

        $gate = Gate::forUser($context->user);

        $projects = Project::query()
            ->where('workspace_id', $context->workspace->id)
            ->with([
                'workspace',
                'agentChannel',
                // The policy reads the viewer's membership; this one row is all it needs.
                'members' => fn ($members) => $members->where('user_id', $context->user->id),
            ])
            ->orderBy('key')
            ->get()
            ->filter(fn (Project $project): bool => $gate->allows('view', $project))
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'key' => $project->key,
                'name' => $project->name,
                'description' => $project->description,
                'repository_url' => $project->repository_url,
                'repository_base_branch' => $project->repository_base_branch,
                'archived' => $project->is_archived,
                'agents_enabled' => $project->agentsEnabled(),
            ])
            ->values()
            ->all();

        return $this->json(['projects' => $projects]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
