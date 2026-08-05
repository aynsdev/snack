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

    public function label(): string
    {
        return match ($this) {
            self::Story => 'Story',
            self::Task => 'Task',
            self::Bug => 'Bug',
            self::Epic => 'Epic',
        };
    }

    /**
     * Whether tasks of this type can hold other tasks.
     *
     * Only epics can, and an epic can never be inside another one — which is
     * what keeps the hierarchy exactly one level deep and the backlog readable.
     */
    public function isContainer(): bool
    {
        return $this === self::Epic;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
