<?php

namespace App\Enums;

/**
 * A member's role inside one project, independent of their workspace role.
 *
 * The mirror of {@see ChannelRole}, and deliberately the same two cases: a
 * workspace Member can lead a project they created, and a workspace Admin is
 * always treated as a project lead by the policies.
 */
enum ProjectRole: string
{
    /** Configures the board, runs sprints, and manages who is on the project. */
    case Lead = 'lead';

    /** Works the board: creates, edits, moves, and is assigned tasks. */
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Project lead',
            self::Member => 'Member',
        };
    }

    /**
     * May rename the project, edit its columns and labels, run its sprints, and
     * add or remove people.
     */
    public function canManageProject(): bool
    {
        return $this === self::Lead;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
