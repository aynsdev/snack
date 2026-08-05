<?php

namespace App\Enums;

/**
 * A member's role within a single workspace.
 *
 * Roles are hierarchical: every capability granted to a lower rank is also
 * granted to every higher rank. Guests sit below Member and can only reach
 * channels they have been explicitly added to.
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Member => 'Member',
            self::Guest => 'Guest',
        };
    }

    /**
     * The role's position in the hierarchy. Higher wins.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 40,
            self::Admin => 30,
            self::Member => 20,
            self::Guest => 10,
        };
    }

    public function isAtLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    /**
     * May edit workspace details, settings, retention, and integrations.
     */
    public function canAdministerWorkspace(): bool
    {
        return $this->isAtLeast(self::Admin);
    }

    /**
     * May invite, remove, suspend, and re-role other members.
     */
    public function canManageMembers(): bool
    {
        return $this->isAtLeast(self::Admin);
    }

    /**
     * May create new channels in the workspace. Guests never can.
     */
    public function canCreateChannels(): bool
    {
        return $this->isAtLeast(self::Member);
    }

    /**
     * May start direct and group conversations.
     */
    public function canStartConversations(): bool
    {
        return $this->isAtLeast(self::Member);
    }

    /**
     * May create projects — a board, a backlog, and the people on them.
     *
     * The same rank as creating a channel, and for the same reason: both make a
     * shared space the whole workspace can be brought into, and a guest is by
     * definition somebody brought into one.
     */
    public function canCreateProjects(): bool
    {
        return $this->isAtLeast(self::Member);
    }

    /**
     * May post an @everyone / @here mention that notifies an entire channel.
     */
    public function canMentionEveryone(): bool
    {
        return $this->isAtLeast(self::Admin);
    }

    /**
     * May read the workspace audit log.
     */
    public function canViewAuditLogs(): bool
    {
        return $this->isAtLeast(self::Admin);
    }

    /**
     * Guests are confined to the channels they were explicitly invited to and
     * never see the public channel browser or the full member directory.
     */
    public function isGuest(): bool
    {
        return $this === self::Guest;
    }

    /**
     * Roles that an administrator is allowed to assign. Ownership transfer is a
     * separate, deliberately narrower operation.
     *
     * @return array<int, self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Member, self::Guest];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
