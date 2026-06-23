<?php

namespace App\Enums;

/**
 * A user's role on a single project, governing which actions they may take.
 * Roles are ranked: an owner outranks an admin, who outranks a plain member.
 */
enum ProjectRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /**
     * The human-readable label for this role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Admin => __('Admin'),
            self::Member => __('Member'),
        };
    }

    /**
     * Privilege rank used for role comparisons; a higher rank outranks a lower one.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }

    /**
     * Whether this role is at least as privileged as the given one.
     */
    public function atLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }
}
