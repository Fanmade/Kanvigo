<?php

namespace App\Enums;

enum Permission: string
{
    case CreateProjects = 'create-projects';
    case AccessAllProjects = 'access-all-projects';
    case InviteUsers = 'invite-users';
    case CreateApiTokens = 'create-api-tokens';
    case ManageUsers = 'manage-users';
    case ManageAccountRoles = 'manage-account-roles';

    /**
     * The human-readable, translatable label for the permission.
     */
    public function label(): string
    {
        return match ($this) {
            self::CreateProjects => __('Create projects'),
            self::AccessAllProjects => __('Access all projects'),
            self::InviteUsers => __('Invite users'),
            self::CreateApiTokens => __('Create API tokens'),
            self::ManageUsers => __('Manage users'),
            self::ManageAccountRoles => __('Manage account roles'),
        };
    }
}
