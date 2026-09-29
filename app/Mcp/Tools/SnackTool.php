<?php

namespace App\Mcp\Tools;

use App\Ai\BiteContext;
use App\Ai\Tools\BiteTool;
use App\Mcp\ConnectorAccess;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Tools\Request as AiRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

/**
 * The half of an MCP tool that is the same in all of them.
 *
 * # Who is asking
 *
 * An MCP client connects with a Passport token that is tied to exactly one
 * workspace — see {@see ConnectorAccess}. So the principal here is always *the
 * person the token belongs to, inside that workspace*, resolved fresh on every
 * call. Nothing a client sends can name a different workspace, and a person
 * suspended since connecting sees nothing.
 *
 * {@see App\Http\Middleware\AuthenticateMcpConnector} refuses the same
 * requests one layer up, at the HTTP boundary. This check is here too because
 * a tool is also callable without that route — from a test, or from a future
 * local transport — and "the middleware covered it" is how a tool ends up
 * trusting a request nobody authenticated.
 *
 * # Reusing Bite
 *
 * Most of what an external assistant wants to do is what Bite already does:
 * search what was said, list channels, find files, look up tasks. Those tools
 * live in App\Ai\Tools and already narrow every query to the asker with the
 * same scopes the screens use. {@see self::delegate()} runs one of them as the
 * token's owner rather than re-implementing it, so there is exactly one
 * definition of what an assistant may read — whichever assistant it is.
 */
abstract class SnackTool extends Tool
{
    /**
     * The workspace and person this call acts as, or null when the token no
     * longer grants anything.
     */
    protected function context(Request $request): ?BiteContext
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $workspace = app(ConnectorAccess::class)->workspaceFor($user);

        return $workspace === null
            ? null
            : new BiteContext(workspace: $workspace, user: $user, container: null);
    }

    /**
     * The answer when {@see self::context()} came back empty. Worded for the
     * person reading their client's error, since that is where it surfaces.
     */
    protected function unauthorized(): Response
    {
        return Response::error('This connector no longer has access to the workspace. Reconnect it from the workspace\'s AI connectors page.');
    }

    /**
     * Run one of Bite's tools as the token's owner and hand back what it said.
     *
     * @param  class-string<BiteTool>  $tool
     */
    protected function delegate(string $tool, Request $request): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->unauthorized();
        }

        /** @var BiteTool&\Laravel\Ai\Contracts\Tool $instance */
        $instance = app()->make($tool, ['context' => $context]);

        return Response::text((string) $instance->handle(new AiRequest($request->all())));
    }

    /**
     * An instance of a Bite tool for reading its description and schema only.
     *
     * Built without its constructor, because both are static text and there is
     * no principal at the time a client lists the tools. Never used to handle a
     * call — {@see self::delegate()} builds a real one for that.
     *
     * @template T of BiteTool
     *
     * @param  class-string<T>  $tool
     * @return T
     */
    protected static function describe(string $tool): BiteTool
    {
        return (new ReflectionClass($tool))->newInstanceWithoutConstructor();
    }

    /**
     * A channel the person can see, named the way a person names one.
     *
     * Accepts `#design`, `design`, or the channel's display name. A private
     * channel the person is not in resolves to null exactly like a channel that
     * does not exist — the same 404-not-403 rule {@see App\Policies\ChannelPolicy::view()}
     * applies on screen, so an assistant cannot be used to confirm that a
     * channel by some name is there.
     */
    protected function channel(BiteContext $context, mixed $named): ?Channel
    {
        $name = str(is_string($named) ? $named : '')->trim()->ltrim('#')->lower()->toString();

        if ($name === '') {
            return null;
        }

        $channel = Channel::query()
            ->where('workspace_id', $context->workspace->id)
            ->where(fn ($query) => $query
                ->where('slug', $name)
                ->orWhereRaw('lower(name) = ?', [$name]))
            ->orderByRaw('slug = ? desc', [$name])
            ->first();

        if ($channel === null || Gate::forUser($context->user)->denies('view', $channel)) {
            return null;
        }

        return $channel;
    }

    /**
     * One message as an assistant should read it: prose, not JSON, for the
     * reasons given on {@see BiteTool} — with the id printed bare so a follow-up
     * call can name it exactly.
     */
    protected function formatMessage(Message $message): string
    {
        /*
         * By id rather than by the relation: `sender_id` is nulled when an
         * account is deleted, and the message outlives it.
         */
        $author = $message->sender_id !== null
            ? "{$message->sender->name} (@{$message->sender->username})"
            : 'A deleted user';
        $when = $message->created_at?->toDayDateTimeString() ?? 'unknown time';

        $line = "message #{$message->id} — {$author}, {$when}";

        if ($message->reply_count > 0) {
            $line .= " · {$message->reply_count} ".str('reply')->plural($message->reply_count);
        }

        if ($message->relationLoaded('attachments') && $message->attachments->isNotEmpty()) {
            $line .= ' · files: '.$message->attachments->pluck('original_name')->implode(', ');
        }

        return $line."\n  ".str($message->body)->trim()->replace("\n", "\n  ")->toString();
    }
}
