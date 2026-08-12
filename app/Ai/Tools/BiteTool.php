<?php

namespace App\Ai\Tools;

use App\Ai\BiteContext;
use Laravel\Ai\Tools\Request;

/**
 * The half of a Bite tool that is the same in all of them.
 *
 * Every tool is constructed with the {@see BiteContext} rather than reading the
 * authenticated user from the container, because there is no authenticated user
 * where these run: a tool executes inside a queued job, on behalf of somebody
 * who is not signed in to that process. Passing the principal explicitly is what
 * makes it impossible to forget which one applies.
 *
 * # Tools return prose, not JSON
 *
 * A tool result goes back to the model as text, and the model reads it the way
 * it reads anything else. Compact labelled lines survive that better than JSON
 * — there are no braces to mistake for content and no keys to hallucinate — and
 * they cost fewer tokens. The one thing that must round-trip exactly is the
 * identifiers, which is why they are printed bare and always in the same place.
 */
abstract class BiteTool
{
    public function __construct(protected readonly BiteContext $context) {}

    /**
     * Render a list of rows for the model, or say plainly that there were none.
     *
     * "No results" is a real answer and has to look like one. Returning an empty
     * string invites the model to fill the silence, which is how a search that
     * found nothing becomes a confidently invented file name.
     *
     * @param  array<int, string>  $rows
     */
    protected function list(array $rows, string $emptyMessage): string
    {
        if ($rows === []) {
            return $emptyMessage;
        }

        return implode("\n", $rows);
    }

    /**
     * One argument from a tool call, or a default when the model omitted it.
     *
     * Never `$request['key']`. The SDK's `Request` implements `offsetGet` as a
     * bare array read, so reaching for an argument the model did not send is a
     * PHP warning and a null — and under a strict error handler, a crashed tool.
     * Optional arguments are omitted constantly and by design, which makes that
     * the single most likely runtime failure in any of these tools.
     */
    protected function arg(Request $request, string $key, mixed $default = null): mixed
    {
        return $request->toArray()[$key] ?? $default;
    }

    /**
     * Clamp a model-supplied limit into something a query can survive.
     *
     * The model chooses this, and a model that has decided the answer needs
     * five hundred rows is one round trip away from an context window overflow
     * and a bill. The ceiling is the tool's, not the model's.
     */
    protected function limit(mixed $requested, int $default, int $max): int
    {
        $limit = is_numeric($requested) ? (int) $requested : $default;

        return max(1, min($limit, $max));
    }

    /**
     * Trim free text down to something worth searching for.
     */
    protected function term(mixed $value, int $limit = 200): string
    {
        return str(is_string($value) ? $value : '')->squish()->limit($limit, '')->toString();
    }
}
