<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * Task authorization resolves each ability to a specific project-scoped
 * permission (KAN-310). The package's Gate::before grants catalog permissions
 * directly when the scope is the project; these methods bridge a task subject to
 * its project for the abilities whose names differ from the catalog permission.
 *
 * Ability names must stay distinct from the catalog permission names: a collision
 * would let Gate::before auto-grant the ability and bypass the method. See the
 * naming contract on {@see ProjectPolicy}; AuthorizationContractTest enforces it.
 */
class TaskPolicy
{
    /**
     * Seeing a task is part of seeing its project.
     */
    public function view(User $user, Task $task): bool
    {
        return $user->hasScopedPermission('view-project', $task->project);
    }

    /**
     * Editing a task's fields (title, description, priority, type, assignees, due
     * date, parent).
     */
    public function update(User $user, Task $task): bool
    {
        return $user->hasScopedPermission('edit-task', $task->project);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->hasScopedPermission('delete-task', $task->project);
    }

    /**
     * Moving a task between columns or reordering it — a status edit short of
     * completing it (see {@see close()}).
     */
    public function updateStatus(User $user, Task $task): bool
    {
        return $user->hasScopedPermission('edit-task', $task->project);
    }

    /**
     * Completing a task (moving it to Done).
     */
    public function close(User $user, Task $task): bool
    {
        return $user->hasScopedPermission('close-task', $task->project);
    }

    /**
     * Cancelling a task, or reopening a cancelled one.
     */
    public function cancel(User $user, Task $task): bool
    {
        return $user->hasScopedPermission('cancel-task', $task->project);
    }

    /**
     * Archiving a task or restoring it from the archive.
     */
    public function archive(User $user, Task $task): bool
    {
        return $user->hasScopedPermission('archive-task', $task->project);
    }

    /**
     * Linking or unlinking the task's dependencies and relationships.
     */
    public function manageDependencies(User $user, Task $task): bool
    {
        return $user->hasScopedPermission('manage-dependencies', $task->project);
    }

    /**
     * Applying or removing tags on the task. (Tagging targets a task, so this
     * lives on the task policy; managing the project's tag catalog is the
     * separate manage-tags permission.)
     */
    public function tag(User $user, Task $task): bool
    {
        return $user->hasScopedPermission('tag-tasks', $task->project);
    }
}
