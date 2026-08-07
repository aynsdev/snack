<?php

namespace App\Support;

use App\Enums\TaskType;
use App\Models\Task;
use Illuminate\Support\Str;

/**
 * The branch somebody should open to work a card — "feature/PWA-1499-cancel-a-ride".
 *
 * # Why the server owns this
 *
 * A branch name is the only thread tying a card to the work done for it, and it
 * is useful precisely because everyone on the team writes it the same way. If
 * each surface that offers to copy one composed its own, they would drift — one
 * capitalising the key, one keeping the punctuation — and the convention would
 * be worth nothing. So it is computed once here, travels on the card, and the
 * client only copies it.
 *
 * # The rules it has to survive
 *
 * git refuses a good many names: spaces, `..`, `~^:?*[\`, a leading `-`, a
 * trailing `.` or `/`, anything ending `.lock`. Slugging the title removes every
 * one of those characters, so the composed name is legal by construction rather
 * than by a list of replacements that has to stay in step with git.
 *
 * The reference is the load-bearing half — a branch missing it cannot be traced
 * back to anything — so a title that slugs away to nothing (all emoji, a script
 * that does not transliterate) yields `feature/PWA-1499` rather than nothing at
 * all.
 */
final class BranchName
{
    /**
     * How much of the title survives.
     *
     * Long enough to say what the work is, short enough that the whole branch
     * fits in a terminal prompt and a PR title. Cut at a word boundary: a name
     * ending mid-word reads as a typo and invites somebody to "fix" it.
     */
    private const TITLE_LIMIT = 48;

    public static function for(Task $task): string
    {
        return self::compose($task->reference(), $task->type, $task->title);
    }

    public static function compose(string $reference, TaskType $type, string $title): string
    {
        $prefix = self::prefix($type);
        $slug = self::slug($title);

        return $slug === ''
            ? "{$prefix}/{$reference}"
            : "{$prefix}/{$reference}-{$slug}";
    }

    /**
     * The folder the branch lives under.
     *
     * The three names teams already use, mapped from the type rather than asked
     * for: the card knows whether it is a bug, and asking again is asking
     * somebody to repeat themselves. Anything that is not a bug or an epic is a
     * feature — the distinction between a story and a task is a planning one,
     * and it does not survive into the repository.
     */
    public static function prefix(TaskType $type): string
    {
        return match ($type) {
            TaskType::Bug => 'bugfix',
            TaskType::Epic => 'epic',
            default => 'feature',
        };
    }

    private static function slug(string $title): string
    {
        $slug = Str::slug($title);

        if (strlen($slug) > self::TITLE_LIMIT) {
            $cut = substr($slug, 0, self::TITLE_LIMIT + 1);
            $boundary = strrpos($cut, '-');

            $slug = $boundary === false
                ? substr($cut, 0, self::TITLE_LIMIT)
                : substr($cut, 0, $boundary);
        }

        return trim($slug, '-');
    }
}
