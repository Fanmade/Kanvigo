<?php

namespace App\Authorization;

use Illuminate\Support\Str;

/**
 * Presentation catalog for the project permission set: maps each catalog
 * permission name to a human-readable, translatable label. This is the single
 * source of truth for how permissions are shown in the role picker; the raw
 * names stay the canonical identifiers (and drive the data-test selectors).
 *
 * Group labels are intentionally not mapped — the {@see ProjectRoleProvisioner}
 * group keys already read well as headings.
 */
class PermissionCatalog
{
    /**
     * Permission name => English label. The label doubles as the translation key
     * in lang/de.json. Every permission in {@see ProjectRoleProvisioner::CATALOG}
     * has an entry; PermissionCatalogTest guards that and the German coverage.
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
    ];

    /**
     * Permission name => English description (the de.json translation key). A
     * short clause explaining what granting the permission actually allows,
     * shown as helper text under the checkbox. Optional — a permission whose
     * label already says it all may be omitted.
     *
     * @var array<string, string>
     */
    public const array DESCRIPTIONS = [
        'view-project' => 'See the project and its tasks',
        'manage-settings' => "Edit the project's title, short name and description",
        'delete-project' => 'Permanently delete the entire project',
        'view-activity-log' => "See the project's activity history",
        'manage-members' => 'Add or remove members and change their roles',
        'invite-members' => 'Invite new people to the project by email',
        'manage-roles' => "Create and edit the project's custom roles",
        'create-task' => 'Add new tasks to the project',
        'edit-task' => "Change a task's title, description and other details",
        'delete-task' => 'Permanently delete tasks',
        'close-task' => 'Move a task to Done',
        'cancel-task' => 'Abandon a task with a reason',
        'archive-task' => 'Archive tasks that are done',
        'manage-dependencies' => 'Mark tasks as blocking or blocked by others',
        'manage-tags' => "Create, rename and delete the project's tags",
        'tag-tasks' => 'Add and remove tags on tasks',
        'manage-attachments' => 'Upload and replace file attachments',
        'delete-attachment' => 'Remove file attachments',
        'create-comment' => 'Post comments on tasks',
        'moderate-comments' => "Edit or delete other people's comments",
    ];

    /**
     * The translated, human-readable label for a permission name. Falls back to a
     * title-cased form of the raw name for anything outside the catalog.
     */
    public static function label(string $permission): string
    {
        return __(self::LABELS[$permission] ?? Str::headline($permission));
    }

    /**
     * The translated description for a permission name, or null when none is
     * defined (descriptions are optional).
     */
    public static function description(string $permission): ?string
    {
        if (! isset(self::DESCRIPTIONS[$permission])) {
            return null;
        }

        return __(self::DESCRIPTIONS[$permission]);
    }
}
