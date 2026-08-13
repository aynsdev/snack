<?php

namespace App\Ai;

use App\Models\BiteMemory;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * The admin-curated facts, rendered for the system prompt.
 *
 * # Why this is a class and not a `->pluck()->join()`
 *
 * Because of the cap. Every enabled entry goes into every answer, so the one
 * thing this must never do is hand the provider an unbounded string — a
 * workspace that pastes a wiki into the screen would turn every "thanks" into a
 * context-window error, and the failure would arrive as a provider exception on
 * a queue worker rather than as a message to whoever pasted it.
 *
 * The request caps each entry as it is saved. This caps the total again at the
 * point of use, because the two limits can disagree: entries accumulate one at
 * a time, and an installation can lower the ceiling in config long after the
 * rows were written.
 *
 * # Dropped, not truncated
 *
 * When the budget runs out the remaining entries are left out whole rather than
 * cut off mid-sentence. Half a fact is worse than no fact: "the staging
 * environment to use is" is the kind of thing a model will finish for itself.
 * The list is fed in the admin's own order, so what survives a squeeze is what
 * they put at the top.
 */
final readonly class BiteKnowledge
{
    /**
     * The block to append to the system prompt, or null when there is nothing
     * to say.
     *
     * Null rather than an empty string so the caller can leave the heading out
     * too — an empty "Things this workspace has told you" section reads to a
     * model as a section it should fill in.
     */
    public function forWorkspace(Workspace $workspace): ?string
    {
        $entries = $workspace->relationLoaded('biteMemories')
            ? $workspace->biteMemories->where('is_enabled', true)->sortBy(['position', 'id'])
            : $workspace->biteMemories()->enabled()->inOrder()->get();

        return $this->render($entries);
    }

    /**
     * @param  Collection<int, BiteMemory>  $entries
     */
    public function render(Collection $entries): ?string
    {
        $budget = (int) config('snack.bite.knowledge.max_characters', 24_000);
        $rendered = [];

        foreach ($entries as $entry) {
            $block = $this->one($entry);

            if ($block === null) {
                continue;
            }

            $cost = mb_strlen($block);

            if ($cost > $budget) {
                /*
                 * Skipped rather than stopping the loop. A single enormous entry
                 * near the top should not cost the workspace the six short ones
                 * beneath it that would all have fitted.
                 */
                continue;
            }

            $budget -= $cost;
            $rendered[] = $block;
        }

        return $rendered === [] ? null : implode("\n\n", $rendered);
    }

    /**
     * One fact, as a heading and its body.
     *
     * Bounded here as well as in the request, for the reason on the class: an
     * entry written when the limit was higher is still in the table.
     */
    private function one(BiteMemory $entry): ?string
    {
        $title = trim($entry->title);
        $body = trim($entry->body);

        if ($body === '') {
            return null;
        }

        $limit = (int) config('snack.bite.knowledge.max_entry_characters', 2_000);

        return sprintf(
            "## %s\n%s",
            $title === '' ? 'Note' : $title,
            mb_substr($body, 0, $limit),
        );
    }
}
