<?php

namespace App\Authorization;

use Illuminate\Support\Str;

/**
 * Presentation catalog for the project permission set: maps each catalog
 * permission name to its human-readable, translatable labels and an optional
 * description. The single source of truth for how permissions are shown; the
 * raw names stay the canonical identifiers (and drive the data-test selectors).
 *
 * Group labels are intentionally not mapped — the {@see ProjectRoleProvisioner}
 * group keys already read well as headings.
 */
class PermissionCatalog
{
    /**
     * Permission name => full standalone English label, for places where a
     * permission is shown without its group as context (e.g. the per-role
     * permission summary). Doubles as the de.json translation key. Every
     * permission in {@see ProjectRoleProvisioner::CATALOG} has an entry;
     * PermissionCatalogTest guards that and the German coverage.
     *
     * @var array<string, string>
     */
    public const array LABELS = [
        'view-project' => 'View project',
        'manage-settings' => 'Manage settings',
        'delete-project' => 'Delete project',
        'view-activity-log' => 'View activity log',
        'manage-members' => 'Manage members',
        'invite-members' => 'Invite members',
        'manage-roles' => 'Manage roles',
        'create-task' => 'Create tasks',
        'edit-task' => 'Edit tasks',
        'delete-task' => 'Delete tasks',
        'close-task' => 'Close tasks',
        'cancel-task' => 'Cancel tasks',
        'archive-task' => 'Archive tasks',
        'manage-dependencies' => 'Manage dependencies',
        'manage-tags' => 'Manage tags',
        'tag-tasks' => 'Tag tasks',
        'manage-attachments' => 'Manage attachments',
        'delete-attachment' => 'Delete attachments',
        'create-comment' => 'Write comments',
        'moderate-comments' => 'Moderate comments',
        'create-doc' => 'Create docs',
        'edit-doc' => 'Edit docs',
        'delete-doc' => 'Delete docs',
    ];

    /**
     * Permission name => short label for the role picker, where the group
     * heading already supplies the subject (so "create-task" under "Tasks" is
     * just "Create"). Groups that span several subjects keep a qualifier.
     *
     * @var array<string, string>
     */
    public const array PICKER_LABELS = [
        'view-project' => 'View',
        'manage-settings' => 'Settings',
        'delete-project' => 'Delete',
        'view-activity-log' => 'Activity log',
        'manage-members' => 'Manage members',
        'invite-members' => 'Invite members',
        'manage-roles' => 'Manage roles',
        'create-task' => 'Create',
        'edit-task' => 'Edit',
        'delete-task' => 'Delete',
        'close-task' => 'Close',
        'cancel-task' => 'Cancel',
        'archive-task' => 'Archive',
        'manage-dependencies' => 'Dependencies',
        'manage-tags' => 'Manage',
        'tag-tasks' => 'Tag tasks',
        'manage-attachments' => 'Manage',
        'delete-attachment' => 'Delete',
        'create-comment' => 'Write',
        'moderate-comments' => 'Moderate',
        'create-doc' => 'Create',
        'edit-doc' => 'Edit',
        'delete-doc' => 'Delete',
    ];

    /**
     * Permission name => English description (the de.json translation key), shown
     * only behind a hint icon in the picker. Deliberately sparse: most short
     * labels speak for themselves, so only permissions whose scope is genuinely
     * non-obvious from the label carry one.
     *
     * @var array<string, string>
     */
    public const array DESCRIPTIONS = [
        'manage-settings' => "Edit the project's title, short name and description",
        'close-task' => 'Move a task to Done',
        'cancel-task' => 'Abandon a task with a reason',
        'manage-dependencies' => 'Mark tasks as blocking or blocked by others',
        'moderate-comments' => "Edit or delete other people's comments",
    ];

    /**
     * The translated full label for a permission name, used where the permission
     * stands alone. Falls back to a title-cased form of the raw name.
     */
    public static function label(string $permission): string
    {
        return __(self::LABELS[$permission] ?? Str::headline($permission));
    }

    /**
     * The translated short label for a permission name, used in the role picker
     * under its group heading. Falls back to the full label.
     */
    public static function pickerLabel(string $permission): string
    {
        return __(self::PICKER_LABELS[$permission] ?? self::LABELS[$permission] ?? Str::headline($permission));
    }

    /**
     * The translated description for a permission name, or null when none is
     * defined (descriptions are optional and only added where they help).
     */
    public static function description(string $permission): ?string
    {
        if (! isset(self::DESCRIPTIONS[$permission])) {
            return null;
        }

        return __(self::DESCRIPTIONS[$permission]);
    }
}
