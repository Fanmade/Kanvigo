<?php

namespace App\Authorization;

use Illuminate\Support\Str;

/**
 * The project-scoped permission catalog: one case per permission, carrying its
 * group and its human-readable, translatable labels. Single source of truth for
 * what the catalog contains, how it is grouped and how it is shown — the flat
 * catalog and the grouped catalog are both derived from the cases, so they
 * cannot drift apart.
 *
 * The backing values are the canonical identifiers: they are the
 * delegated-permissions `Permission` names, the strings passed to `can()`, and
 * the data-test selectors. Names therefore cross string boundaries, so the
 * lookup helpers ({@see labelFor()} and friends) keep taking a string and fall
 * back for names outside the catalog — custom roles rely on that.
 *
 * Group labels are intentionally plain strings: they already read well as
 * headings and are translated at the point of display.
 */
enum ProjectPermission: string
{
    case ViewProject = 'view-project';
    case ManageSettings = 'manage-settings';
    case DeleteProject = 'delete-project';
    case ViewActivityLog = 'view-activity-log';
    case ExportContent = 'export-content';
    case ExportProject = 'export-project';

    case ManageMembers = 'manage-members';
    case InviteMembers = 'invite-members';
    case ManageRoles = 'manage-roles';

    case CreateTask = 'create-task';
    case EditTask = 'edit-task';
    case DeleteTask = 'delete-task';
    case CloseTask = 'close-task';
    case CancelTask = 'cancel-task';
    case ArchiveTask = 'archive-task';
    case ManageDependencies = 'manage-dependencies';

    case ManageTags = 'manage-tags';
    case TagTasks = 'tag-tasks';

    case ManageAttachments = 'manage-attachments';
    case DeleteAttachment = 'delete-attachment';

    case CreateComment = 'create-comment';
    case ModerateComments = 'moderate-comments';

    case CreateDoc = 'create-doc';
    case EditDoc = 'edit-doc';
    case DeleteDoc = 'delete-doc';

    case ManageVariables = 'manage-variables';

    /**
     * The group heading the permission is shown under. The case order within a
     * group decides the order inside it; the order the groups first appear in
     * decides the group order.
     */
    public function group(): string
    {
        return match ($this) {
            self::ViewProject, self::ManageSettings, self::DeleteProject,
            self::ViewActivityLog, self::ExportContent, self::ExportProject => 'Project',
            self::ManageMembers, self::InviteMembers, self::ManageRoles => 'Members & roles',
            self::CreateTask, self::EditTask, self::DeleteTask, self::CloseTask,
            self::CancelTask, self::ArchiveTask, self::ManageDependencies => 'Tasks',
            self::ManageTags, self::TagTasks => 'Tags',
            self::ManageAttachments, self::DeleteAttachment => 'Attachments',
            self::CreateComment, self::ModerateComments => 'Comments',
            self::CreateDoc, self::EditDoc, self::DeleteDoc => 'Docs',
            self::ManageVariables => 'Variables',
        };
    }

    /**
     * The full standalone English label, for places where a permission is shown
     * without its group as context (e.g. the per-role permission summary).
     * Doubles as the de.json translation key.
     */
    public function label(): string
    {
        return match ($this) {
            self::ViewProject => 'View project',
            self::ManageSettings => 'Manage settings',
            self::DeleteProject => 'Delete project',
            self::ViewActivityLog => 'View activity log',
            self::ExportContent => 'Export content',
            self::ExportProject => 'Export the whole project',
            self::ManageMembers => 'Manage members',
            self::InviteMembers => 'Invite members',
            self::ManageRoles => 'Manage roles',
            self::CreateTask => 'Create tasks',
            self::EditTask => 'Edit tasks',
            self::DeleteTask => 'Delete tasks',
            self::CloseTask => 'Close tasks',
            self::CancelTask => 'Cancel tasks',
            self::ArchiveTask => 'Archive tasks',
            self::ManageDependencies => 'Manage dependencies',
            self::ManageTags => 'Manage tags',
            self::TagTasks => 'Tag tasks',
            self::ManageAttachments => 'Manage attachments',
            self::DeleteAttachment => 'Delete attachments',
            self::CreateComment => 'Write comments',
            self::ModerateComments => 'Moderate comments',
            self::CreateDoc => 'Create docs',
            self::EditDoc => 'Edit docs',
            self::DeleteDoc => 'Delete docs',
            self::ManageVariables => 'Manage variables',
        };
    }

    /**
     * The short label for the role picker, where the group heading already
     * supplies the subject (so "create-task" under "Tasks" is just "Create").
     * Groups that span several subjects keep a qualifier.
     */
    public function pickerLabel(): string
    {
        return match ($this) {
            self::ViewProject => 'View',
            self::ManageSettings => 'Settings',
            self::DeleteProject => 'Delete',
            self::ViewActivityLog => 'Activity log',
            self::ExportContent => 'Export',
            self::ExportProject => 'Export project',
            self::ManageMembers => 'Manage members',
            self::InviteMembers => 'Invite members',
            self::ManageRoles => 'Manage roles',
            self::CreateTask => 'Create',
            self::EditTask => 'Edit',
            self::DeleteTask => 'Delete',
            self::CloseTask => 'Close',
            self::CancelTask => 'Cancel',
            self::ArchiveTask => 'Archive',
            self::ManageDependencies => 'Dependencies',
            self::ManageTags => 'Manage',
            self::TagTasks => 'Tag tasks',
            self::ManageAttachments => 'Manage',
            self::DeleteAttachment => 'Delete',
            self::CreateComment => 'Write',
            self::ModerateComments => 'Moderate',
            self::CreateDoc => 'Create',
            self::EditDoc => 'Edit',
            self::DeleteDoc => 'Delete',
            self::ManageVariables => 'Manage',
        };
    }

    /**
     * The English description (the de.json translation key), shown only behind a
     * hint icon in the picker. Deliberately sparse: most short labels speak for
     * themselves, so only permissions whose scope is genuinely non-obvious from
     * the label carry one.
     */
    public function description(): ?string
    {
        return match ($this) {
            self::ManageSettings => "Edit the project's title, short name and description",
            self::CloseTask => 'Move a task to Done',
            self::CancelTask => 'Abandon a task with a reason',
            self::ManageDependencies => 'Mark tasks as blocking or blocked by others',
            self::ModerateComments => "Edit or delete other people's comments",
            self::ManageVariables => 'Create variables and set what they stand for',
            self::ExportContent => 'Download tasks and docs as files, or copy them out',
            self::ExportProject => 'Take the entire project out in one archive',
            default => null,
        };
    }

    /**
     * The flat catalog: every permission name, in case order. The owner role
     * holds exactly this set.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The catalog grouped for the management UI: group heading => permission
     * names. The group order and contents drive how the permission picker is
     * laid out.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            $groups[$permission->group()][] = $permission->value;
        }

        return $groups;
    }

    /**
     * The translated full label for a permission name, used where the permission
     * stands alone. Falls back to a title-cased form of a name outside the
     * catalog.
     */
    public static function labelFor(string $permission): string
    {
        return __(self::tryFrom($permission)?->label() ?? Str::headline($permission));
    }

    /**
     * The translated short label for a permission name, used in the role picker
     * under its group heading. Falls back like {@see labelFor()}.
     */
    public static function pickerLabelFor(string $permission): string
    {
        return __(self::tryFrom($permission)?->pickerLabel() ?? Str::headline($permission));
    }

    /**
     * The translated description for a permission name, or null when none is
     * defined (descriptions are optional and only added where they help).
     */
    public static function descriptionFor(string $permission): ?string
    {
        $description = self::tryFrom($permission)?->description();

        return $description === null ? null : (string) __($description);
    }
}
