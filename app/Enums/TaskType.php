<?php

namespace App\Enums;

/**
 * What kind of work a task represents.
 *
 * A fixed set rather than a per-project table. The type drives the icon and
 * colour on the card and — for Epic — how the task is allowed to relate to
 * others, so every case here has behaviour attached to it; a project inventing
 * a seventh kind would get an icon nobody had drawn and no rules to go with it.
 * What a project *can* customize is which of these it shows on its cards and the
 * labels it hangs on them, both of which are data rather than behaviour.
 */
enum TaskType: string
{
    /** A slice of user-facing value. The default. */
    case Story = 'story';

    /** A unit of work with no user-facing story around it. */
    case Task = 'task';

    /** Something that is broken. */
    case Bug = 'bug';

    /** A container for other tasks, spanning sprints. */
    case Epic = 'epic';

    case Subtask = 'subtask';

    public function label(): string
    {
        return match ($this) {
            self::Story => 'Story',
            self::Task => 'Task',
            self::Bug => 'Bug',
            self::Epic => 'Epic',
            self::Subtask => 'Subtask',
        };
    }

    /**
     * Whether tasks of this type sit at the top of the hierarchy.
     *
     * Any task can have subtasks, but an epic is never inside another task:
     * it is what the rest of the work is grouped under.
     */
    public function isContainer(): bool
    {
        return $this === self::Epic;
    }

    /**
     * Whether this is a piece of another card, as Jira's subtasks are: it
     * always has a parent, and never has subtasks of its own.
     */
    public function isSubtask(): bool
    {
        return $this === self::Subtask;
    }

    /**
     * Whether a card of this type may have `$child` beneath it.
     *
     * Nothing goes under a subtask, and a subtask goes under a story, task,
     * or bug — never straight under an epic, which groups whole pieces of
     * work rather than the steps of one.
     */
    public function canHoldChild(self $child): bool
    {
        if ($this->isSubtask() || $child->isContainer()) {
            return false;
        }

        return ! ($child->isSubtask() && $this->isContainer());
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
