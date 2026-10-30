<?php

namespace App\Mcp\Tools;

use App\Enums\ActorVia;
use App\Services\ActorContext;
use App\Services\Outbound\AgentAttestation;
use App\Support\SharedSecret;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Proof, for the agent dispatcher, of which person and workspace an agent
 * connector belongs to — see {@see AgentAttestation}.
 *
 * Agent connectors only. An ordinary token has no business pairing with the
 * dispatcher, and refusing it here means a token pasted into the wrong client
 * cannot be turned into an agent identity.
 *
 * The whole text of a successful result is the attestation and nothing else,
 * so the caller can hand it on without parsing prose.
 */
#[Name('agent_attestation')]
#[Title('Agent Attestation')]
#[Description(<<<'TEXT'
For agent connectors only: get a short-lived, signed attestation of who this
connector belongs to, over a nonce the agent dispatcher issued. The result text
is the attestation string itself. Pass it to the dispatcher unchanged.
TEXT)]
#[IsReadOnly]
class AgentAttestationTool extends SnackTool
{
    /**
     * Listed, and callable, only for an agent connector; the check in
     * {@see handle()} stays as a second line.
     */
    public function shouldRegister(ActorContext $actors): bool
    {
        return $actors->current()->via === ActorVia::Agent;
    }

    public function handle(Request $request, ActorContext $actors, AgentAttestation $attestations): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        $actor = $actors->current();

        if (! $actor->isAgentFor($context->user)) {
            return Response::error('agent_attestation requires an agent connector. Create one on the workspace\'s AI connectors page with the kind set to Agent.');
        }

        $secret = config('snack.agents.dispatcher_secret');

        if (blank($secret) || SharedSecret::problem((string) $secret) !== null) {
            return Response::error('This Snack installation has no agent dispatcher configured, so it cannot issue attestations.');
        }

        $nonce = $request->get('nonce');

        if (! is_string($nonce) || preg_match(AgentAttestation::NONCE_PATTERN, $nonce) !== 1) {
            return Response::error('The nonce must be 16 to 128 characters of letters, digits, "-" or "_".');
        }

        return Response::text($attestations->issue($context->workspace, $context->user, $actor, $nonce));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'nonce' => $schema->string()
                ->description('The nonce the agent dispatcher issued: 16 to 128 characters of A-Z, a-z, 0-9, "-" or "_".')
                ->required(),
        ];
    }
}
