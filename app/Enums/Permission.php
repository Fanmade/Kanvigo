<?php

namespace App\Enums;

enum Permission: string
{
    case CreateProjects = 'create-projects';
    case InviteUsers = 'invite-users';

    /**
     * The human-readable, translatable label for the permission.
     */
    public function label(): string
    {
        return match ($this) {
            self::CreateProjects => __('Create projects'),
            self::InviteUsers => __('Invite users'),
        };
    }
}
