<?php

namespace App\Http\Requests\Workspaces;

use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The workspace's own details and configuration.
 *
 * Gated on WorkspacePolicy::update, which admits admins and the owner. The
 * workspace comes from {@see WorkspaceContext} rather than from anything the
 * request names, so there is no identifier here that could point at another
 * tenant.
 */
class UpdateWorkspaceRequest extends FormRequest
{
    public function authorize(WorkspaceContext $context): Response
    {
        return Gate::inspect('update', $context->workspace());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(WorkspaceContext $context): array
    {
        $workspace = $context->workspace();

        return [
            'name' => ['required', 'string', 'min:1', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],

            /*
             * The floor is the number of people already in the workspace. A
             * limit set below the current roster would not remove anyone — it
             * would just make every future invitation fail with "this workspace
             * has reached its member limit", which reads as a bug rather than as
             * the setting somebody typed.
             */
            'member_limit' => [
                'required',
                'integer',
                'min:'.max(1, $workspace->members()->count()),
                'max:100000',
            ],

            'allow_invitations' => ['required', 'boolean'],
            'allow_self_serve_joins' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member_limit.min' => 'The limit cannot be lower than the :min people already in this workspace.',
        ];
    }

    /**
     * @return array{name: string, description: string|null, member_limit: int}
     */
    public function workspaceAttributes(): array
    {
        $description = $this->input('description');

        return [
            'name' => $this->string('name')->squish()->toString(),
            'description' => is_string($description) && trim($description) !== ''
                ? trim($description)
                : null,
            'member_limit' => $this->integer('member_limit'),
        ];
    }

    /**
     * The full settings document.
     *
     * Rebuilt rather than merged, so the jsonb column holds exactly the keys the
     * application reads — see {@see Workspace::allowsInvitations()} and
     * {@see Workspace::allowsSelfServeChannelJoins()} — and cannot accumulate
     * ones nothing looks at.
     *
     * @return array<string, mixed>
     */
    public function workspaceSettings(): array
    {
        return [
            'auth' => ['allow_invitations' => $this->boolean('allow_invitations')],
            'channels' => ['allow_self_serve_joins' => $this->boolean('allow_self_serve_joins')],
        ];
    }
}
